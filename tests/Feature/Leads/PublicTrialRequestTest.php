<?php

use App\Domain\Leads\Enums\BusinessType;
use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

const TRIAL_URL = '/api/v1/public/trial-requests';

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    config(['services.turnstile.secret' => '', 'sspos.public_form_origins' => ['https://switchandsave.co.uk']]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function trialBody(array $overrides = []): array
{
    return array_replace([
        'businessName' => 'Khan Mini Mart',
        'contactName' => 'Ali Khan',
        'email' => 'Ali@KhanMart.test',
        'phone' => '07700 900123',
        'town' => 'Leeds',
        'postcode' => 'ls16bx',
        'shopsCount' => 2,
        'tillsCount' => 3,
        'businessType' => 'ConvenienceOffLicence',
        'currentSystem' => 'Pen and paper',
        'marketingConsent' => true,
        'utm' => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'autumn'],
        'captchaToken' => 'token-123',
        'website' => '',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @param  array<string, string>  $headers
 */
function postTrial(object $test, array $overrides = [], array $headers = [], string $ip = '203.0.113.9'): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(TRIAL_URL, trialBody($overrides), $headers);
}

test('a trial request creates a website lead with utm and ip, and alerts staff', function () {
    $response = postTrial($this)->assertCreated();

    $lead = Lead::query()->sole();

    $response->assertExactJson(['reference' => $lead->reference(), 'message' => $response->json('message')]);
    expect($response->json('reference'))->toMatch('/^TR-[0-9A-Z]{6}$/')
        ->and($response->json('message'))->toContain('Thank you')
        ->and($lead->source)->toBe(LeadSource::Website)
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->email)->toBe('ali@khanmart.test')
        ->and($lead->postcode)->toBe('LS1 6BX')
        ->and($lead->business_type)->toBe(BusinessType::Convenience)
        ->and($lead->shops_count)->toBe(2)
        ->and($lead->tills_count)->toBe(3)
        ->and($lead->consent_marketing)->toBeTrue()
        ->and($lead->utm)->toBe(['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'autumn'])
        ->and($lead->ip)->toBe('203.0.113.9')
        ->and($lead->notes()->where('kind', LeadNoteKind::Created->value)->sole()->body)->toBe('Trial request received from the website');

    Mail::assertQueued(AdminNewLeadMail::class, 1);

    // Staff can find the lead by the reference the prospect was given.
    $this->actingAs($this->admin(), 'admin')->get(route('admin.leads.index', ['search' => $lead->reference()]))
        ->assertInertia(fn (Assert $page) => $page->where('leads.data.0.id', $lead->id));
});

test('a till business type the lead list has no kind for is kept in the message', function () {
    postTrial($this, ['businessType' => 'Pharmacy', 'utm' => null, 'currentSystem' => null])->assertCreated();

    $lead = Lead::query()->sole();

    expect($lead->business_type)->toBe(BusinessType::Other)
        ->and($lead->message)->toBe('Business type: Pharmacy')
        ->and($lead->utm)->toBeNull();
});

test('invalid requests get 400 request.invalid with field errors', function () {
    postTrial($this, ['businessName' => '', 'email' => 'not-an-email', 'shopsCount' => 3, 'tillsCount' => 2, 'businessType' => 'convenience'])
        ->assertStatus(400)
        ->assertJsonPath('code', 'request.invalid')
        ->assertJsonStructure(['code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey', 'details' => ['fields']])
        ->assertJsonPath('details.fields.businessName.0', 'Enter your business name.')
        ->assertJsonPath('details.fields.email.0', 'Enter a valid email address, like name@yourshop.co.uk.')
        ->assertJsonPath('details.fields.tillsCount.0', 'Enter at least one till for each shop.')
        ->assertJsonPath('details.fields.businessType.0', 'Choose the kind of business you run.');

    expect(Lead::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('a filled honeypot gets a normal reply but creates nothing', function () {
    postTrial($this, ['website' => 'https://spam.example', 'email' => 'bad'])
        ->assertCreated()
        ->assertJsonStructure(['reference', 'message']);

    expect(Lead::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('Turnstile is checked server-side when a secret is set', function () {
    config(['services.turnstile.secret' => 'secret-key']);
    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
        ->push(['success' => true])
        ->push(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    postTrial($this)->assertCreated();

    Http::assertSent(fn (HttpRequest $request) => $request['secret'] === 'secret-key'
        && $request['response'] === 'token-123'
        && $request['remoteip'] === '203.0.113.9');

    postTrial($this, ['email' => 'other@shop.test', 'phone' => '0113 496 0000'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'captcha.failed');

    // No token: refused without calling Cloudflare.
    postTrial($this, ['captchaToken' => null, 'email' => 'third@shop.test', 'phone' => '0113 496 0001'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'captcha.failed');

    expect(Lead::query()->count())->toBe(1);
    Http::assertSentCount(2);
});

test('outside local and testing a missing secret refuses requests', function () {
    app()->detectEnvironment(fn () => 'production');

    postTrial($this)->assertStatus(422)->assertJsonPath('code', 'captcha.failed');
    expect(Lead::query()->count())->toBe(0);
});

test('five requests an hour per IP; form typos do not count', function () {
    postTrial($this, ['email' => ''])->assertStatus(400);

    foreach (range(1, 5) as $i) {
        postTrial($this, ['email' => "shop{$i}@example.test", 'phone' => "0113 496 000{$i}"])->assertCreated();
    }

    postTrial($this, ['email' => 'shop6@example.test', 'phone' => '0113 496 0006'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate.limited')
        ->assertHeader('Retry-After');

    postTrial($this, ['email' => 'shop6@example.test', 'phone' => '0113 496 0006'], ip: '198.51.100.7')->assertCreated();
    expect(Lead::query()->count())->toBe(6);
});

test('three requests a day per email address', function () {
    foreach (range(1, 3) as $i) {
        postTrial($this, ip: "198.51.100.{$i}")->assertCreated();
    }

    postTrial($this, ip: '198.51.100.4')->assertStatus(429)->assertJsonPath('code', 'rate.limited');
    expect(Lead::query()->count())->toBe(1);
});

test('a repeat request becomes a note on the open lead', function () {
    $existing = Lead::factory()->create(['email' => 'ali@khanmart.test', 'phone' => '07700900123', 'status' => LeadStatus::Contacted]);

    $response = postTrial($this, ['email' => 'someone@else.test'])->assertCreated();
    expect(Lead::query()->count())->toBe(1);

    postTrial($this, ['phone' => '+44 7700 900999', 'email' => 'ALI@khanmart.test'])->assertCreated()
        ->assertJsonPath('reference', $existing->reference());

    expect(Lead::query()->count())->toBe(1)
        ->and($response->json('reference'))->toBe($existing->reference())
        ->and($existing->notes()->where('kind', LeadNoteKind::Duplicate->value)->count())->toBe(2)
        ->and($existing->notes()->first()->body)->toContain('Asked for a trial again from the website');

    Mail::assertQueued(AdminNewLeadMail::class, 2);
});

test('a closed lead with the same email does not swallow a new request', function () {
    Lead::factory()->create(['email' => 'ali@khanmart.test', 'status' => LeadStatus::Rejected]);

    postTrial($this)->assertCreated();

    expect(Lead::query()->count())->toBe(2)
        ->and(Lead::query()->latest('id')->first()->notes()->where('kind', LeadNoteKind::Duplicate->value)->exists())->toBeTrue();
});

test('CORS: listed origins and our own page are allowed, others are blocked', function () {
    $this->call('OPTIONS', TRIAL_URL, server: ['HTTP_ORIGIN' => 'https://switchandsave.co.uk', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://switchandsave.co.uk')
        ->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');

    postTrial($this, headers: ['Origin' => 'https://switchandsave.co.uk'])
        ->assertCreated()
        ->assertHeader('Access-Control-Allow-Origin', 'https://switchandsave.co.uk');

    postTrial($this, ['email' => 'own@page.test', 'phone' => '0113 496 0100'], ['Origin' => rtrim(config('app.url'), '/')])->assertCreated();

    $this->call('OPTIONS', TRIAL_URL, server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
        ->assertForbidden()
        ->assertJsonPath('code', 'cors.origin_not_allowed')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    postTrial($this, ['email' => 'evil@spam.test', 'phone' => '0113 496 0200'], ['Origin' => 'https://evil.example'])->assertForbidden();

    expect(Lead::query()->count())->toBe(2);
});

test('the hosted /trial page renders for guests', function () {
    config(['services.turnstile.site_key' => '0x4AAAAAAA-site']);

    $this->get('/trial')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('trial')
            ->where('endpoint', TRIAL_URL)
            ->where('turnstileSiteKey', '0x4AAAAAAA-site')
            ->has('businessTypes', 10)
            ->where('businessTypes.0.value', 'ConvenienceOffLicence'));
});
