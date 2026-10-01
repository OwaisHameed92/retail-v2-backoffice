<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Security\Enums\TwoFactorArea;
use App\Domain\Security\Support\RecoveryCodes;
use App\Domain\Security\Support\RememberedDevice;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Security\TwoFactorHelpers as TF;

/*
 * Two-factor sign-in for admins: required for everyone, set up after the first password sign-in.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->admin = Admin::factory()->create(['email' => 'staff@sspos.test', 'role' => AdminRole::Support]);
});

function signInWithPassword(object $test, string $email = 'staff@sspos.test'): void
{
    $test->post('/admin/login', ['email' => $email, 'password' => 'password'])->assertRedirect('/admin');
}

test('an admin without two-factor is sent to set it up after the password', function () {
    signInWithPassword($this);

    $this->get('/admin')->assertRedirect(route('admin.two-factor.setup'));
    $this->get('/admin/tenants')->assertRedirect(route('admin.two-factor.setup'));
    $this->getJson('/admin/search?q=khan')->assertForbidden();

    $this->get('/admin/two-factor/setup')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('auth/two-factor-setup')
        ->where('staff', true)
        ->where('required', true)
        ->where('email', 'staff@sspos.test')
        ->where('setup.qrSvg', fn (string $svg) => str_contains($svg, '<svg'))
        ->where('setup.secret', fn (string $secret) => preg_match('/^[A-Z2-7]{4}( [A-Z2-7]{4}){7}$/', $secret) === 1));
});

test('the set-up keeps the same secret on reload and turns two-factor on with the first code', function () {
    signInWithPassword($this);

    $first = null;
    $this->get('/admin/two-factor/setup')->assertInertia(function (Assert $page) use (&$first) {
        $first = $page->toArray()['props']['setup']['secret'];
    });
    $this->get('/admin/two-factor/setup')->assertInertia(fn (Assert $page) => $page->where('setup.secret', $first));

    $secret = str_replace(' ', '', $first);
    $this->postJson('/admin/two-factor/setup', ['code' => TF::wrongCode($secret)])->assertUnprocessable()->assertJsonValidationErrors('code');

    $reply = $this->postJson('/admin/two-factor/setup', ['code' => TF::code($secret)])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $codes = $reply->json('recoveryCodes');

    expect($codes)->toHaveCount(10)
        ->and($codes[0])->toMatch('/^[a-z2-9]{5}-[a-z2-9]{5}$/');

    $admin = $this->admin->fresh();
    $raw = DB::table('admins')->where('id', $admin->id)->first();
    expect($admin->hasTwoFactorEnabled())->toBeTrue()
        ->and($admin->two_factor_secret)->toBe($secret)
        ->and($raw->two_factor_secret)->not->toContain($secret)
        ->and($raw->two_factor_recovery_codes)->not->toContain($codes[0])
        ->and($admin->two_factor_recovery_codes)->toContain(RecoveryCodes::hash($codes[0]));

    $entry = AuditLog::query()->where('action', 'two_factor.enabled')->sole();
    expect($entry->actor_id)->toBe($admin->id)->and($entry->subject_id)->toBe($admin->id)->and($entry->company_id)->toBeNull();

    $this->get('/admin')->assertOk();
    $this->get('/admin/two-factor/setup')->assertRedirect(route('admin.dashboard'));
});

test('an admin with two-factor must enter a code after the password, and a code works once', function () {
    ['secret' => $secret] = TF::enable($this->admin);
    signInWithPassword($this);

    $this->get('/admin/leads')->assertRedirect(route('admin.two-factor.challenge'));
    $this->get('/admin/two-factor/challenge')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('auth/two-factor-challenge')->where('staff', true)->where('rememberDays', 30));

    $code = TF::code($secret);
    $this->post('/admin/two-factor/challenge', ['code' => $code])->assertRedirect('/admin/leads');
    $this->get('/admin')->assertOk();

    // The same code cannot be replayed in another session.
    $this->flushSession();
    $this->post('/admin/login', ['email' => 'staff@sspos.test', 'password' => 'password']);
    $this->from('/admin/two-factor/challenge')->post('/admin/two-factor/challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->get('/admin')->assertRedirect(route('admin.two-factor.challenge'));
});

test('a recovery code signs in once and is audited', function () {
    ['codes' => $codes] = TF::enable($this->admin);
    signInWithPassword($this);

    $this->post('/admin/two-factor/challenge', ['code' => strtoupper($codes[3])])->assertRedirect('/admin');
    $this->get('/admin')->assertOk();

    expect($this->admin->fresh()->remainingRecoveryCodes())->toBe(9);
    $entry = AuditLog::query()->where('action', 'two_factor.recovery_code_used')->sole();
    expect($entry->meta)->toMatchArray(['guard' => 'admin', 'remaining' => 9]);

    $this->flushSession();
    signInWithPassword($this);
    $this->post('/admin/two-factor/challenge', ['code' => $codes[3]])->assertSessionHasErrors('code');
});

test('five wrong codes lock code entry for a minute, logged and audited', function () {
    ['secret' => $secret] = TF::enable($this->admin);
    Log::spy();
    signInWithPassword($this);

    foreach (range(1, 5) as $ignored) {
        $this->post('/admin/two-factor/challenge', ['code' => TF::wrongCode($secret)])->assertSessionHasErrors('code');
    }

    $this->post('/admin/two-factor/challenge', ['code' => TF::code($secret)])->assertSessionHasErrors('code');
    expect(session('errors')->first('code'))->toContain('Too many wrong codes');
    $this->get('/admin')->assertRedirect(route('admin.two-factor.challenge'));

    expect(AuditLog::query()->where('action', 'two_factor.locked_out')->count())->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Two-factor code entry locked'))->once();

    $this->travel(61)->seconds();
    $this->post('/admin/two-factor/challenge', ['code' => TF::code($secret)])->assertRedirect('/admin');
});

test('remember this device skips the code for 30 days until two-factor is reset', function () {
    ['secret' => $secret] = TF::enable($this->admin);
    signInWithPassword($this);

    $response = $this->post('/admin/two-factor/challenge', ['code' => TF::code($secret), 'remember' => true]);
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === RememberedDevice::cookieName(TwoFactorArea::Admin));
    expect($cookie)->not->toBeNull()->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp());

    $this->flushSession();
    signInWithPassword($this);
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/admin')->assertOk();

    // After 31 days the device is asked again.
    $this->flushSession();
    $this->travel(31)->days();
    signInWithPassword($this);
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/admin')->assertRedirect(route('admin.two-factor.challenge'));
});

test('a remembered device stops working when the secret changes', function () {
    TF::enable($this->admin);
    $admin = $this->admin->fresh();
    $value = RememberedDevice::issue(TwoFactorArea::Admin, $admin)->getValue();

    TF::enable($admin);

    signInWithPassword($this);
    $this->withCookie(RememberedDevice::cookieName(TwoFactorArea::Admin), $value)->get('/admin')
        ->assertRedirect(route('admin.two-factor.challenge'));
});

test('the set-up cannot replace an existing secret, even with the password alone', function () {
    TF::enable($this->admin);
    signInWithPassword($this);

    $this->get('/admin/two-factor/setup')->assertRedirect(route('admin.dashboard'));
    $this->postJson('/admin/two-factor/setup', ['code' => '123456'])->assertUnprocessable();
});

test('guests are sent to the admin sign-in from the two-factor pages', function () {
    $this->get('/admin/two-factor/challenge')->assertRedirect(route('admin.login'));
    $this->get('/admin/two-factor/setup')->assertRedirect(route('admin.login'));
    $this->post('/admin/two-factor/challenge', ['code' => '123456'])->assertRedirect(route('admin.login'));
});

test('admins can still log out from the two-factor step', function () {
    signInWithPassword($this);

    $this->post('/admin/logout')->assertRedirect(route('admin.login'));
    $this->assertGuest('admin');
});

test('an owner resets another admin\'s two-factor; it is audited and they must set it up again', function () {
    $owner = Admin::factory()->owner()->create();
    TF::enable($this->admin);

    $this->actingAs($owner, 'admin')->post(route('admin.admins.two-factor.reset', $this->admin))->assertRedirect()->assertSessionHas('success');

    expect($this->admin->fresh()->hasTwoFactorEnabled())->toBeFalse();
    $entry = AuditLog::query()->where('action', 'admin.two_factor_reset')->sole();
    expect($entry->actor_id)->toBe($owner->id)->and($entry->subject_id)->toBe($this->admin->id);

    $this->flushSession();
    $this->app['auth']->forgetGuards();
    signInWithPassword($this);
    $this->get('/admin')->assertRedirect(route('admin.two-factor.setup'));
});

test('a signed-in admin whose two-factor is reset is stopped at their next request', function () {
    TF::enable($this->admin);
    $admin = $this->admin->fresh();
    $this->actingAs($admin, 'admin')->get('/admin')->assertOk();

    $admin->clearTwoFactor();

    $this->get('/admin')->assertRedirect(route('admin.two-factor.setup'));
});

test('only owners can reset two-factor, and never their own', function () {
    $owner = Admin::factory()->owner()->create();
    TF::enable($owner);
    TF::enable($this->admin);

    $this->actingAs($this->admin, 'admin')->post(route('admin.admins.two-factor.reset', $owner))->assertForbidden();
    $this->actingAs($owner, 'admin')->post(route('admin.admins.two-factor.reset', $owner))->assertForbidden();

    expect($owner->fresh()->hasTwoFactorEnabled())->toBeTrue()->and($this->admin->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('the admin users list shows each admin\'s two-factor status', function () {
    $owner = Admin::factory()->owner()->create();
    TF::enable($owner);

    $this->actingAs($owner, 'admin')->get('/admin/admins')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('admins', fn ($admins) => collect($admins)->firstWhere('id', $owner->id)['twoFactorEnabled'] === true
            && collect($admins)->firstWhere('id', $this->admin->id)['twoFactorEnabled'] === false));
});

test('an admin makes new recovery codes with their password; the old ones stop working', function () {
    ['codes' => $old] = TF::enable($this->admin);
    $admin = $this->admin->fresh();

    $this->actingAs($admin, 'admin')->get('/admin/security')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/security')->where('twoFactor.enabled', true)->where('twoFactor.recoveryCodesLeft', 10));

    $this->postJson('/admin/security/recovery-codes', ['password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $new = $this->postJson('/admin/security/recovery-codes', ['password' => 'password'])->assertOk()->json('recoveryCodes');

    expect($new)->toHaveCount(10)->and(array_intersect($new, $old))->toBe([]);
    expect($admin->fresh()->two_factor_recovery_codes)->not->toContain(RecoveryCodes::hash($old[0]));
    expect(AuditLog::query()->where('action', 'two_factor.recovery_codes_regenerated')->count())->toBe(1);
});

test('login as customer needs the admin\'s own two-factor', function () {
    $company = Company::factory()->create();
    $owner = User::factory()->create();
    $company->users()->attach($owner->id, ['role' => 'owner', 'is_active' => true]);
    ['secret' => $secret] = TF::enable($this->admin);
    signInWithPassword($this);

    $this->post("/admin/tenants/{$company->id}/impersonate", ['user_id' => $owner->id])->assertRedirect(route('admin.two-factor.challenge'));
    $this->assertGuest('web');

    $this->post('/admin/two-factor/challenge', ['code' => TF::code($secret)]);
    $this->post("/admin/tenants/{$company->id}/impersonate", ['user_id' => $owner->id])->assertRedirect(route('app.dashboard'));
    $this->get('/app')->assertOk();

    // Losing the admin's two-factor state closes the customer's portal too.
    session()->forget('two_factor_passed.admin');
    $this->get('/app')->assertRedirect(route('admin.two-factor.challenge'));
});
