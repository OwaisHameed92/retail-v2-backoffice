<?php

use App\Domain\Handheld\Models\HandheldToken;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\PortalUsers\PortalUsersHelpers as P;

/*
 * Settings → Handheld (PDA) users (`company.can:users.manage`): list, add, edit, switch off and on, new password
 * (signs devices out), sign devices out. Other roles get 403; another business's handheld users are 404 and never
 * listed.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo('2026-10-20 09:00:00');
    $this->company = Company::factory()->create(['name' => 'Kirkgate Stores']);
    $this->owner = P::member($this->company, CompanyRole::Owner, attributes: ['name' => 'Aisha Khan']);
    $this->leeds = Branch::factory()->forCompany($this->company)->create(['code' => 'LDS', 'name' => 'Leeds Kirkgate']);
    $this->bradford = Branch::factory()->forCompany($this->company)->create(['code' => 'BRD', 'name' => 'Bradford']);
    $this->handheld = H::user($this->company, $this->leeds);

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherShop = Branch::factory()->forCompany($this->other)->create(['code' => 'OTH']);
    $this->otherHandheld = H::user($this->other, $this->otherShop, ['name' => 'Their scanner', 'email' => 'their@other.test']);
    $this->new = ['name' => 'Stock room 2', 'email' => 'Stock2@Kirkgate.test', 'branch_id' => $this->bradford->id, 'can_change_prices' => true,
        'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'];
});

function handheldRoutes(object $t, HandheldUser $user): array
{
    return [
        ['get', '/app/handheld-users'],
        ['post', '/app/handheld-users'],
        ['put', "/app/handheld-users/{$user->id}"],
        ['post', "/app/handheld-users/{$user->id}/deactivate"],
        ['post', "/app/handheld-users/{$user->id}/reactivate"],
        ['post', "/app/handheld-users/{$user->id}/password"],
        ['post', "/app/handheld-users/{$user->id}/sign-out"],
    ];
}

test('guests go to sign-in and roles without users.manage get 403 on every route', function (?CompanyRole $role) {
    $actor = $role === null ? null : P::member($this->company, $role);

    foreach (handheldRoutes($this, $this->handheld) as [$method, $url]) {
        $response = $actor === null ? $this->{$method}($url, $this->new) : $this->actingAs($actor)->{$method}($url, $this->new);
        $actor === null ? $response->assertRedirect('/login') : $response->assertForbidden();
    }

    expect(HandheldUser::withoutCompanyScope()->count())->toBe(2)->and($this->handheld->fresh()->is_active)->toBeTrue();
})->with([null, CompanyRole::Manager, CompanyRole::Accountant, CompanyRole::Staff]);

test('the owner sees this business\'s handheld users, its open shops and the counts, never a password', function () {
    H::token($this, $this->handheld);

    $this->actingAs($this->owner)->get('/app/handheld-users')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/handheld-users/index')
        ->has('users', 1)
        ->where('users.0.id', $this->handheld->id)
        ->where('users.0.name', 'Stock room 1')
        ->where('users.0.shopName', 'Leeds Kirkgate')
        ->where('users.0.devices', 1)
        ->where('users.0.canChangePrices', false)
        ->where('users.0.lastLoginAt', '2026-10-20T09:00:00Z')
        ->missing('users.0.password')
        ->has('shops', 2)
        ->where('stats', ['active' => 1, 'inactive' => 0, 'canChangePrices' => 0, 'devices' => 1]));
});

test('the owner adds a handheld user with a bcrypt password; the email is unique within the business only', function () {
    $this->actingAs($this->owner)->post('/app/handheld-users', $this->new)->assertRedirect()->assertSessionHas('success');

    $added = HandheldUser::withoutCompanyScope()->where('email', 'stock2@kirkgate.test')->sole();
    expect($added->company_id)->toBe($this->company->id)->and($added->branch_id)->toBe($this->bradford->id)
        ->and($added->can_change_prices)->toBeTrue()->and($added->created_by_user_id)->toBe($this->owner->id)
        ->and(Hash::check('a-long-enough-password', $added->password))->toBeTrue()->and($added->password)->not->toBe('a-long-enough-password');

    $audit = AuditLog::query()->where('action', 'handheld_user.created')->sole();
    expect($audit->actor_id)->toBe((string) $this->owner->id)->and(json_encode($audit->toArray()))->not->toContain('a-long-enough-password');

    $this->actingAs($this->owner)->post('/app/handheld-users', [...$this->new, 'name' => 'Twin'])->assertSessionHasErrors('email');
    $this->actingAs($this->owner)->post('/app/handheld-users', [...$this->new, 'email' => 'their@other.test'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/app/handheld-users', [...$this->new, 'email' => 'x@kirkgate.test', 'branch_id' => $this->otherShop->id])->assertSessionHasErrors('branch_id');
    $this->actingAs($this->owner)->post('/app/handheld-users', [...$this->new, 'email' => 'y@kirkgate.test', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
    $this->actingAs($this->owner)->post('/app/handheld-users', [...$this->new, 'email' => 'z@kirkgate.test', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');

    $this->bradford->forceFill(['is_active' => false])->saveQuietly();
    $this->actingAs($this->owner)->post('/app/handheld-users', [...$this->new, 'email' => 'closed@kirkgate.test'])->assertSessionHasErrors('branch_id');
});

test('editing changes name, email, shop and price permission; the device follows at its next call', function () {
    $token = H::token($this, $this->handheld);

    $this->actingAs($this->owner)->put("/app/handheld-users/{$this->handheld->id}", ['name' => 'Scanner A', 'email' => 'scanner-a@kirkgate.test',
        'branch_id' => $this->bradford->id, 'can_change_prices' => true])->assertSessionHas('success');

    expect($this->handheld->fresh())->name->toBe('Scanner A')->email->toBe('scanner-a@kirkgate.test')->branch_id->toBe($this->bradford->id)->can_change_prices->toBeTrue();
    $this->getJson('/api/pda/me', H::headers($token))->assertOk()->assertJsonPath('user.shop_id', $this->bradford->id)->assertJsonPath('user.can_change_prices', true);
    expect(AuditLog::query()->where('action', 'handheld_user.updated')->sole()->after)->toMatchArray(['name' => 'Scanner A', 'branch_id' => $this->bradford->id]);
});

test('switching off signs every device out and blocks sign-in; switching on lets them sign in again', function () {
    $token = H::token($this, $this->handheld);

    $this->actingAs($this->owner)->post("/app/handheld-users/{$this->handheld->id}/deactivate")->assertSessionHas('success');
    expect($this->handheld->fresh()->is_active)->toBeFalse()
        ->and(HandheldToken::withoutCompanyScope()->whereNull('revoked_at')->count())->toBe(0);
    $this->getJson('/api/pda/me', H::headers($token))->assertUnauthorized();
    H::login($this, ['email' => $this->handheld->email, 'password' => H::PASSWORD])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');

    $this->actingAs($this->owner)->post("/app/handheld-users/{$this->handheld->id}/reactivate")->assertSessionHas('success');
    H::login($this, ['email' => $this->handheld->email, 'password' => H::PASSWORD])->assertOk();
    expect(AuditLog::query()->whereIn('action', ['handheld_user.deactivated', 'handheld_user.reactivated'])->count())->toBe(2);
});

test('a new password signs every device out; sign out devices revokes the live tokens', function () {
    $first = H::token($this, $this->handheld);

    $this->actingAs($this->owner)->post("/app/handheld-users/{$this->handheld->id}/password", ['password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password'])
        ->assertSessionHas('success');
    $this->getJson('/api/pda/me', H::headers($first))->assertUnauthorized();
    H::login($this, ['email' => $this->handheld->email, 'password' => H::PASSWORD])->assertUnauthorized();
    $second = H::token($this, $this->handheld, 'a-brand-new-password');

    $this->actingAs($this->owner)->post("/app/handheld-users/{$this->handheld->id}/sign-out")->assertSessionHas('success');
    $this->getJson('/api/pda/me', H::headers($second))->assertUnauthorized();

    $actions = AuditLog::query()->where('subject_id', $this->handheld->id)->pluck('action')->all();
    expect($actions)->toContain('handheld_user.password_reset')->toContain('handheld_user.signed_out')
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('a-brand-new-password');
});

test('another business\'s handheld user is never listed and 404 on every change', function () {
    $this->actingAs($this->owner)->get('/app/handheld-users')->assertInertia(fn (Assert $page) => $page->has('users', 1)->where('users.0.name', 'Stock room 1'));

    foreach (array_slice(handheldRoutes($this, $this->otherHandheld), 2) as [$method, $url]) {
        $this->actingAs($this->owner)->{$method}($url, ['name' => 'Hijack', 'email' => 'h@x.test', 'branch_id' => $this->leeds->id, 'can_change_prices' => true,
            'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'])->assertNotFound();
    }

    expect($this->otherHandheld->fresh())->name->toBe('Their scanner')->is_active->toBeTrue()
        ->and(Hash::check(H::PASSWORD, $this->otherHandheld->fresh()->password))->toBeTrue();
});
