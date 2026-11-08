<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Workshop\Actions\DisconnectWorkshop;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Models\WorkshopCallLog;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopToken;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Workshop\WorkshopFixtures as W;

/*
 * Connector auth (WORKSHOP-BIKEBOOK.md §1.2): OAuth 2.0 authorisation code + refresh with the shop owner's consent,
 * the ApiKey header fallback, tenant-id 403, keys never in the query string, disconnect → 401, rate limit 429.
 */

beforeEach(function () {
    $this->travelTo('2026-11-08 09:00:00');
    Http::preventStrayRequests();
    $this->sync = new SyncApiFixtures($this);
    $this->key = W::connected($this->sync);
});

/** The owner's consent; returns the code from BikeBook's redirect. */
function consent(object $test, ?string $state = 'xyz'): string
{
    $owner = F::member($test->sync->company, CompanyRole::Owner);
    $query = http_build_query(['response_type' => 'code', 'client_id' => 'bikebook-test-client', 'redirect_uri' => 'https://workshop.bikebook.co.uk/oauth/callback', 'scope' => 'offline_access products:read salesorders:write', 'state' => $state]);

    $test->actingAs($owner)->get('/oauth/authorize?'.$query)->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/workshop/authorize')->where('error', null)
            ->where('shops.0.name', 'Bradford')->where('shops.0.included', false)->where('shops.1.included', true)->etc());

    $response = $test->actingAs($owner)->post('/oauth/authorize', [
        'client_id' => 'bikebook-test-client', 'redirect_uri' => 'https://workshop.bikebook.co.uk/oauth/callback',
        'scope' => 'offline_access products:read salesorders:write', 'state' => $state, 'branch_id' => $test->sync->leeds->id, 'decision' => 'approve',
    ]);
    $location = (string) ($response->headers->get('Location') ?? $response->headers->get('X-Inertia-Location'));
    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

    expect($location)->toStartWith('https://workshop.bikebook.co.uk/oauth/callback?')->and($params['state'] ?? null)->toBe($state);

    return (string) $params['code'];
}

test('API key: the ApiKey header works; no key, a wrong key and the query string are refused', function () {
    W::call($this, 'GET', '/Stores', $this->key)->assertOk()
        ->assertExactJson([['id' => $this->sync->leeds->id, 'name' => 'Leeds Kirkgate', 'address' => collect([$this->sync->leeds->address, $this->sync->leeds->town, $this->sync->leeds->postcode])->filter()->implode(', ')]]);

    $this->getJson(W::BASE.'/Stores')->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
    W::call($this, 'GET', '/Stores', 'sswk_'.str_repeat('a', 40))->assertUnauthorized();
    $this->getJson(W::BASE.'/Stores?apikey='.$this->key)->assertStatus(400)->assertJsonPath('error', 'invalid_request')
        ->assertJsonPath('message', 'Send the API key in the ApiKey header or a Bearer token. Keys are never accepted in the URL.');
    $this->getJson(W::BASE.'/Stores?ApiKey='.$this->key, W::headers($this->key))->assertStatus(400);

    $connection = W::connection($this->sync->leeds);
    expect($connection->api_key_hash)->not->toContain($this->key)->and($connection->api_key_last4)->toBe(substr($this->key, -4))
        ->and(WorkshopCallLog::withoutCompanyScope()->pluck('path')->implode(' '))->not->toContain($this->key);
});

test('a tenant-id that is not the connected shop gets 403; the right one passes', function () {
    W::call($this, 'GET', '/TaxRates', $this->key, [], ['tenant-id' => $this->sync->bradford->id])->assertForbidden()->assertJsonPath('error', 'forbidden');
    W::call($this, 'GET', '/TaxRates', $this->key, [], ['tenant-id' => $this->sync->leeds->id])->assertOk();
});

test('OAuth: consent → code → tokens with tenant = the shop; Bearer works; refresh rotates; a used code is refused and revokes its tokens', function () {
    $code = consent($this);
    $credentials = ['client_id' => 'bikebook-test-client', 'client_secret' => 'bikebook-test-secret-value'];

    $this->postJson('/oauth/token', [...$credentials, 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://wrong.example.com'])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    $tokens = $this->postJson('/oauth/token', [...$credentials, 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://workshop.bikebook.co.uk/oauth/callback'])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('token_type', 'Bearer')->assertJsonPath('expires_in', 86400)->assertJsonPath('tenant', $this->sync->leeds->id)->json();

    $bearer = ['Authorization' => 'Bearer '.$tokens['access_token'], 'tenant-id' => $this->sync->leeds->id];
    $this->getJson(W::BASE.'/TaxRates', $bearer)->assertOk();
    $this->getJson(W::BASE.'/TaxRates', [...$bearer, 'tenant-id' => $this->sync->bradford->id])->assertForbidden();
    $this->getJson(W::BASE.'/TaxRates?access_token='.$tokens['access_token'])->assertStatus(400);

    $refreshed = $this->postJson('/oauth/token', [...$credentials, 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']])->assertOk()->json();
    $this->getJson(W::BASE.'/TaxRates', $bearer)->assertUnauthorized();
    $this->getJson(W::BASE.'/TaxRates', ['Authorization' => 'Bearer '.$refreshed['access_token']])->assertOk();
    $this->postJson('/oauth/token', [...$credentials, 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    // The code again: refused, and every token it gave is revoked.
    $this->postJson('/oauth/token', [...$credentials, 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://workshop.bikebook.co.uk/oauth/callback'])->assertStatus(400);
    $this->getJson(W::BASE.'/TaxRates', ['Authorization' => 'Bearer '.$refreshed['access_token']])->assertUnauthorized();

    expect(WorkshopToken::withoutCompanyScope()->pluck('token_hash')->implode(''))->not->toContain($tokens['access_token']);
});

test('OAuth: wrong client secret 401 invalid_client; unknown grant 400; consent for an unregistered redirect never redirects; declining sends access_denied', function () {
    $this->postJson('/oauth/token', ['client_id' => 'bikebook-test-client', 'client_secret' => 'nope', 'grant_type' => 'authorization_code', 'code' => 'x'])
        ->assertUnauthorized()->assertJsonPath('error', 'invalid_client')->assertJsonPath('error_description', 'Unknown client or wrong client secret.');
    $this->withBasicAuth('bikebook-test-client', 'bikebook-test-secret-value')->postJson('/oauth/token', ['grant_type' => 'password'])
        ->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');

    $owner = F::member($this->sync->company, CompanyRole::Owner);
    $this->actingAs($owner)->get('/oauth/authorize?response_type=code&client_id=bikebook-test-client&redirect_uri=https://evil.example.com/cb')
        ->assertOk()->assertInertia(fn ($page) => $page->where('error', 'This link sends you to an address BikeBook did not register. Start the connection again from BikeBook.')->where('shops', [])->etc());
    $this->actingAs($owner)->post('/oauth/authorize', ['client_id' => 'bikebook-test-client', 'redirect_uri' => 'https://evil.example.com/cb', 'decision' => 'approve', 'branch_id' => $this->sync->leeds->id])
        ->assertSessionHasErrors('request');

    $deny = $this->actingAs($owner)->post('/oauth/authorize', ['client_id' => 'bikebook-test-client', 'redirect_uri' => 'https://workshop.bikebook.co.uk/oauth/callback', 'state' => 's1', 'decision' => 'deny']);
    expect((string) ($deny->headers->get('Location') ?? $deny->headers->get('X-Inertia-Location')))->toBe('https://workshop.bikebook.co.uk/oauth/callback?error=access_denied&state=s1');

    // A shop without the add-on cannot be approved.
    $this->actingAs($owner)->post('/oauth/authorize', ['client_id' => 'bikebook-test-client', 'redirect_uri' => 'https://workshop.bikebook.co.uk/oauth/callback', 'decision' => 'approve', 'branch_id' => $this->sync->bradford->id])
        ->assertSessionHasErrors('workshop');
});

test('consent needs the owner: a manager gets 403 and a guest is sent to sign in', function () {
    $this->get('/oauth/authorize?client_id=bikebook-test-client')->assertRedirect(route('login'));
    $manager = F::member($this->sync->company, CompanyRole::Manager);
    $this->actingAs($manager)->get('/oauth/authorize?client_id=bikebook-test-client')->assertForbidden();
});

test('disconnect: BikeBook\'s key and tokens get 401 at once (joint test 10)', function () {
    $code = consent($this);
    $tokens = $this->postJson('/oauth/token', ['client_id' => 'bikebook-test-client', 'client_secret' => 'bikebook-test-secret-value', 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://workshop.bikebook.co.uk/oauth/callback'])->json();

    app(DisconnectWorkshop::class)->handle(W::connection($this->sync->leeds));

    W::call($this, 'GET', '/Stores', $this->key)->assertUnauthorized();
    $this->getJson(W::BASE.'/Stores', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertUnauthorized();
    $this->postJson('/oauth/token', ['client_id' => 'bikebook-test-client', 'client_secret' => 'bikebook-test-secret-value', 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
    expect(W::connection($this->sync->leeds)->status)->toBe(ConnectionStatus::Disconnected);
});

test('a suspended business or a licence without the add-on gets 403', function () {
    $this->sync->company->forceFill(['status' => CompanyStatus::Suspended])->save();
    W::call($this, 'GET', '/Stores', $this->key)->assertForbidden();

    $this->sync->company->forceFill(['status' => CompanyStatus::Active])->save();
    $this->sync->leeds->forceFill(['licence_add_ons' => null])->save();
    W::call($this, 'GET', '/Stores', $this->key)->assertForbidden()->assertJsonPath('message', 'Workshop integration is not in this shop\'s licence. The shop should contact Switch & Save.');
});

test('600 requests a minute per connection, then 429 with Retry-After; every call is logged without the key', function () {
    config(['workshop.requests_per_minute' => 3]);

    foreach (range(1, 3) as $i) {
        W::call($this, 'GET', '/TaxRates', $this->key, [], ['X-Request-Id' => "req-{$i}"])->assertOk();
    }

    W::call($this, 'GET', '/TaxRates', $this->key)->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error', 'rate_limited');

    $logs = WorkshopCallLog::withoutCompanyScope()->orderBy('id')->get();
    expect($logs)->toHaveCount(4)->and($logs->first()->path)->toBe('/connectors/bikebook/v1/api/TaxRates')
        ->and($logs->first()->request_id)->toBe('req-1')->and($logs->last()->status)->toBe(429)->and($logs->first()->auth)->toBe('key')
        ->and(WorkshopConnection::withoutCompanyScope()->first()->last_pulled_at)->not->toBeNull();
});

test('the call log keeps the last 1,000 per connection', function () {
    config(['workshop.log_keep' => 5]);

    foreach (range(1, 8) as $i) {
        W::call($this, 'GET', '/Stores', $this->key)->assertOk();
    }

    expect(WorkshopCallLog::withoutCompanyScope()->count())->toBe(5);
});
