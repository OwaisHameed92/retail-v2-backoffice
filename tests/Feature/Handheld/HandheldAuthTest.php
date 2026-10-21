<?php

use App\Domain\Handheld\Models\HandheldToken;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Handheld\HandheldFixtures as H;

/*
 * Handheld (PDA) API phase 1: sign-in. Login → token (plain only in the reply; stored as SHA-256 + last 4), me,
 * logout, the 401/403 answers, the error envelope, tokens only in the Authorization header, and the named limiters.
 */

beforeEach(function () {
    $this->travelTo('2026-10-20 09:00:00');
    $this->company = Company::factory()->create(['name' => 'Kirkgate Stores']);
    $this->leeds = Branch::factory()->forCompany($this->company)->create(['code' => 'LDS', 'name' => 'Leeds Kirkgate']);
    H::monthlyPlan($this->company);
    $this->user = H::user($this->company, $this->leeds);
});

function assertPdaError(TestResponse $response, int $status, string $code): void
{
    $response->assertStatus($status)->assertJsonPath('success', false)->assertJsonPath('code', $code)
        ->assertJsonStructure(['success', 'code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey']);
}

test('login returns a bearer token, the user and the business; only a hash and the last 4 are stored', function () {
    $reply = H::login($this, ['email' => 'Stock1@Kirkgate.test ', 'password' => H::PASSWORD, 'device_name' => 'Zebra TC21'])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'success' => true,
            'token_type' => 'Bearer',
            'user' => ['id' => $this->user->id, 'name' => 'Stock room 1', 'email' => 'stock1@kirkgate.test', 'shop_id' => $this->leeds->id,
                'shop_name' => 'Leeds Kirkgate', 'can_change_prices' => false],
            'tenant' => ['id' => $this->company->id, 'name' => 'Kirkgate Stores'],
        ]);
    $token = (string) $reply->json('token');
    $row = HandheldToken::withoutCompanyScope()->sole();

    expect($token)->toStartWith('pda_')->toHaveLength(52)
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->token_last4)->toBe(substr($token, -4))
        ->and($row->device_name)->toBe('Zebra TC21')
        ->and($row->company_id)->toBe($this->company->id)
        ->and($this->user->fresh()->last_login_at?->toIso8601ZuluString())->toBe('2026-10-20T09:00:00Z');

    $audit = AuditLog::query()->where('action', 'handheld.login')->sole();
    expect($audit->actor_type)->toBe((new HandheldUser)->getMorphClass())->and($audit->actor_id)->toBe($this->user->id)
        ->and($audit->company_id)->toBe($this->company->id)
        ->and(json_encode($audit->toArray()))->not->toContain($token)->not->toContain(H::PASSWORD);

    $this->getJson('/api/pda/me', H::headers($token))->assertOk()
        ->assertJson(['success' => true, 'user' => ['id' => $this->user->id, 'shop_id' => $this->leeds->id], 'tenant' => ['id' => $this->company->id]]);
});

test('a wrong password or unknown email is 401 INVALID_CREDENTIALS, with the same answer', function () {
    assertPdaError(H::login($this, ['email' => 'stock1@kirkgate.test', 'password' => 'wrong-password']), 401, 'INVALID_CREDENTIALS');
    assertPdaError(H::login($this, ['email' => 'nobody@kirkgate.test', 'password' => H::PASSWORD]), 401, 'INVALID_CREDENTIALS');
    assertPdaError(H::login($this, ['email' => 'stock1@kirkgate.test']), 422, 'VALIDATION_FAILED');

    expect(HandheldToken::withoutCompanyScope()->count())->toBe(0);
});

test('an inactive account, a closed shop and a business on hold or closed are 403 with their own code', function () {
    $body = ['email' => $this->user->email, 'password' => H::PASSWORD];

    $this->user->forceFill(['is_active' => false])->saveQuietly();
    assertPdaError(H::login($this, $body), 403, 'ACCOUNT_INACTIVE');
    assertPdaError(H::login($this, [...$body, 'password' => 'wrong-password']), 401, 'INVALID_CREDENTIALS');
    $this->user->forceFill(['is_active' => true])->saveQuietly();

    $this->leeds->forceFill(['is_active' => false])->saveQuietly();
    assertPdaError(H::login($this, $body), 403, 'SHOP_CLOSED');
    $this->leeds->forceFill(['is_active' => true])->saveQuietly();

    $this->company->forceFill(['status' => CompanyStatus::Suspended])->saveQuietly();
    assertPdaError(H::login($this, $body), 403, 'BUSINESS_SUSPENDED');

    $this->company->forceFill(['status' => CompanyStatus::Cancelled])->saveQuietly();
    assertPdaError(H::login($this, $body), 403, 'BUSINESS_CANCELLED');

    expect(HandheldToken::withoutCompanyScope()->count())->toBe(0);
});

test('a live token stops working when the business is suspended or the user is switched off', function () {
    $token = H::token($this, $this->user);
    $this->getJson('/api/pda/me', H::headers($token))->assertOk();

    $this->company->forceFill(['status' => CompanyStatus::Suspended])->saveQuietly();
    assertPdaError($this->getJson('/api/pda/me', H::headers($token)), 403, 'BUSINESS_SUSPENDED');

    $this->company->forceFill(['status' => CompanyStatus::Active])->saveQuietly();
    $this->user->forceFill(['is_active' => false])->saveQuietly();
    assertPdaError($this->getJson('/api/pda/products', H::headers($token)), 403, 'ACCOUNT_INACTIVE');
});

test('logout revokes only that token; a revoked token is 401', function () {
    $first = H::token($this, $this->user);
    $second = H::token($this, $this->user);

    $this->postJson('/api/pda/logout', [], H::headers($first))->assertOk()->assertJson(['success' => true]);

    assertPdaError($this->getJson('/api/pda/me', H::headers($first)), 401, 'UNAUTHENTICATED');
    $this->getJson('/api/pda/me', H::headers($second))->assertOk();
    expect(AuditLog::query()->where('action', 'handheld.logout')->sole()->actor_id)->toBe($this->user->id);
});

test('the token is read from the Authorization header only, never the query string', function () {
    $token = H::token($this, $this->user);

    assertPdaError($this->getJson('/api/pda/me?token='.$token), 401, 'UNAUTHENTICATED');
    assertPdaError($this->getJson('/api/pda/me?api_token='.$token), 401, 'UNAUTHENTICATED');
    assertPdaError($this->getJson('/api/pda/me?access_token='.$token), 401, 'UNAUTHENTICATED');
    assertPdaError($this->getJson('/api/pda/me'), 401, 'UNAUTHENTICATED');
    assertPdaError($this->getJson('/api/pda/me', H::headers('pda_'.str_repeat('x', 48))), 401, 'UNAUTHENTICATED');
    assertPdaError($this->getJson('/api/pda/me', H::headers('not-a-token')), 401, 'UNAUTHENTICATED');
});

test('one email in two businesses: the password picks the account, or tenant_id when both match', function () {
    $other = Company::factory()->create(['name' => 'Other Stores']);
    $otherShop = Branch::factory()->forCompany($other)->create(['code' => 'OTH']);
    $twin = H::user($other, $otherShop, ['password' => 'a-different-password']);

    H::login($this, ['email' => $twin->email, 'password' => 'a-different-password'])->assertOk()->assertJsonPath('tenant.id', $other->id);

    $twin->forceFill(['password' => H::PASSWORD])->saveQuietly();
    assertPdaError(H::login($this, ['email' => $twin->email, 'password' => H::PASSWORD]), 409, 'TENANT_REQUIRED');
    H::login($this, ['email' => $twin->email, 'password' => H::PASSWORD, 'tenant_id' => $this->company->id])->assertOk()->assertJsonPath('tenant.id', $this->company->id);
});

test('login is rate limited per email and IP by its own limiter', function () {
    config(['handheld.login_per_minute' => 3]);

    foreach (range(1, 3) as $i) {
        H::login($this, ['email' => $this->user->email, 'password' => 'wrong-password'])->assertUnauthorized();
    }

    $limited = H::login($this, ['email' => $this->user->email, 'password' => H::PASSWORD]);
    assertPdaError($limited, 429, 'RATE_LIMITED');
    expect($limited->json('retryAfterSeconds'))->toBeGreaterThan(0)->and($limited->headers->get('Retry-After'))->not->toBeNull();

    // Another email from the same IP is its own bucket.
    H::login($this, ['email' => 'nobody@kirkgate.test', 'password' => 'x'])->assertUnauthorized();
});

test('API calls are rate limited per token', function () {
    config(['handheld.api_per_minute' => 2]);
    $token = H::token($this, $this->user);
    $other = H::token($this, $this->user);

    $this->getJson('/api/pda/me', H::headers($token))->assertOk();
    $this->getJson('/api/pda/me', H::headers($token))->assertOk();
    assertPdaError($this->getJson('/api/pda/me', H::headers($token)), 429, 'RATE_LIMITED');
    $this->getJson('/api/pda/me', H::headers($other))->assertOk();
});

test('unknown pda routes and wrong methods use the PDA error envelope', function () {
    $token = H::token($this, $this->user);

    assertPdaError($this->getJson('/api/pda/nothing-here', H::headers($token)), 404, 'NOT_FOUND');
    assertPdaError($this->deleteJson('/api/pda/me', [], H::headers($token)), 405, 'METHOD_NOT_ALLOWED');
});
