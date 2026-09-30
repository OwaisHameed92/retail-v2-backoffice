<?php

use App\Domain\PortalUsers\Actions\AcceptInvitation;
use App\Domain\PortalUsers\Actions\RevokeInvitation;
use App\Domain\PortalUsers\Enums\InvitationStatus;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\SwitchCurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\PortalUsers\PortalUsersHelpers as H;

/*
 * Module 4.1: the emailed invitation link and AcceptInvitation.
 */

beforeEach(function () {
    Mail::fake();
    $this->company = Company::factory()->create(['name' => 'Khan Mini Mart']);
    $this->owner = H::member($this->company, CompanyRole::Owner, attributes: ['name' => 'Aisha Khan']);
    $this->shop = Branch::factory()->forCompany($this->company)->create(['name' => 'Leeds Road']);
});

test('a new person sees the register form, creates an account and lands in the portal limited to their shop', function () {
    [$invitation, $link] = H::invite($this->company, $this->owner, 'bilal@example.test', CompanyRole::Manager, $this->shop->id);

    $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/accept-invitation')
        ->where('state', 'register')
        ->where('businessName', 'Khan Mini Mart')
        ->where('inviterName', 'Aisha Khan')
        ->where('roleLabel', 'Manager')
        ->where('branchName', 'Leeds Road')
        ->where('email', 'bilal@example.test'));

    $this->post($link, ['name' => 'Bilal Ahmed', 'password' => 'a-Str0ng-passw0rd!', 'password_confirmation' => 'a-Str0ng-passw0rd!'])
        ->assertRedirect(route('app.dashboard'))
        ->assertSessionHas(SwitchCurrentCompany::SESSION_KEY, $this->company->id);

    $user = User::query()->where('email', 'bilal@example.test')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Bilal Ahmed')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(H::membership($this->company, $user))->role->toBe('manager')->branch_id->toBe($this->shop->id)
        ->and($invitation->fresh()->status())->toBe(InvitationStatus::Accepted)
        ->and(AuditLog::query()->where('action', 'company.invitation_accepted')->where('actor_id', (string) $user->id)->count())->toBe(1);

    // One-shop user: the portal is locked to their shop.
    $this->get('/app')->assertInertia(fn (Assert $page) => $page->where('branchLocked', true)->where('companyRole', 'manager'));

    // The link works once.
    auth()->logout();
    $this->get($link)->assertInertia(fn (Assert $page) => $page->where('state', 'accepted'));
});

test('a new account needs a name and a confirmed strong password', function () {
    [, $link] = H::invite($this->company, $this->owner);

    $this->from($link)->post($link, ['name' => '', 'password' => 'x', 'password_confirmation' => 'y'])
        ->assertSessionHasErrors(['name', 'password']);

    $this->assertGuest();
    expect(User::query()->where('email', 'new.person@example.test')->exists())->toBeFalse();
});

test('someone with an account signs in first, comes back and joins with one click', function () {
    $existing = H::member(Company::factory()->create(), CompanyRole::Owner, attributes: ['email' => 'known@example.test']);
    [, $link] = H::invite($this->company, $this->owner, 'known@example.test', CompanyRole::Accountant);

    $this->get($link)->assertInertia(fn (Assert $page) => $page->where('state', 'signIn'))
        ->assertSessionHas('url.intended', $link);

    // A guest cannot take over the account from the link.
    $this->post($link, ['name' => 'Hijack', 'password' => 'a-Str0ng-passw0rd!', 'password_confirmation' => 'a-Str0ng-passw0rd!'])
        ->assertSessionHasErrors('invitation');
    $this->assertGuest();

    $this->actingAs($existing)->get($link)->assertInertia(fn (Assert $page) => $page->where('state', 'join'));
    $this->actingAs($existing)->post($link)->assertRedirect(route('app.dashboard'));

    expect(H::membership($this->company, $existing))->role->toBe('accountant')
        ->and($existing->fresh()->name)->not->toBe('Hijack');
});

test('signed in as someone else: the link says so, and "Not you?" signs out and returns to the link', function () {
    $other = H::member(Company::factory()->create(), CompanyRole::Owner, attributes: ['email' => 'other@example.test']);
    [, $link] = H::invite($this->company, $this->owner, 'bilal@example.test');

    $this->actingAs($other)->get($link)->assertInertia(fn (Assert $page) => $page->where('state', 'wrongAccount')->where('signedInAs', 'other@example.test'));
    $this->actingAs($other)->post($link)->assertSessionHasErrors('invitation');
    expect(H::membership($this->company, $other))->toBeNull();

    $this->actingAs($other)->delete($link)->assertRedirect($link);
    $this->assertGuest();
});

test('a tampered, wrong-token, expired or revoked link shows nothing to accept', function () {
    [$invitation, $link] = H::invite($this->company, $this->owner);

    $this->get(str_replace('signature=', 'signature=0', $link))->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));
    $this->get(preg_replace('#/app/invitations/([^/]+)/[^?]+#', '/app/invitations/$1/'.str_repeat('a', 48), $link))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'invalid')->missing('businessName'));

    $this->post(str_replace('signature=', 'signature=0', $link), ['name' => 'X', 'password' => 'a-Str0ng-passw0rd!', 'password_confirmation' => 'a-Str0ng-passw0rd!'])
        ->assertSessionHasErrors('invitation');

    $this->travel(8)->days();
    $this->get($link)->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));
    $this->travelBack();

    app(RevokeInvitation::class)->handle($this->company, $invitation);
    $this->get($link)->assertInertia(fn (Assert $page) => $page->where('state', 'revoked'));
    $this->post($link, ['name' => 'X', 'password' => 'a-Str0ng-passw0rd!', 'password_confirmation' => 'a-Str0ng-passw0rd!'])->assertSessionHasErrors('invitation');

    expect(User::query()->where('email', 'new.person@example.test')->exists())->toBeFalse();
});

test('the action checks the token itself and reactivates a membership deactivated since the invitation', function () {
    $former = User::factory()->create(['email' => 'former@example.test']);
    [$invitation, $link] = H::invite($this->company, $this->owner, 'former@example.test', CompanyRole::Manager, $this->shop->id);
    $this->company->users()->attach($former->id, ['role' => 'staff', 'is_active' => false]);
    $token = explode('/', parse_url($link, PHP_URL_PATH))[4];

    expect(fn () => app(AcceptInvitation::class)->handle($invitation, 'wrong-token', $former))->toThrow(ValidationException::class);

    app(AcceptInvitation::class)->handle($invitation, $token, $former);

    expect(H::membership($this->company, $former))->role->toBe('manager')->branch_id->toBe($this->shop->id)->is_active->toBeTruthy();
});

test('a cancelled business\'s invitation cannot be accepted', function () {
    [, $link] = H::invite($this->company, $this->owner);
    $this->company->forceFill(['status' => 'cancelled'])->save();

    $this->get($link)->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
});
