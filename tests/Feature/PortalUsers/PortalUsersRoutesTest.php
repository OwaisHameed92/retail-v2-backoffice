<?php

use App\Domain\Mail\Mailables\PortalInvitationMail;
use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\PortalUsers\PortalUsersHelpers as H;

/*
 * Module 4.1: /app/users routes — who may use them (owner only), what the page shows, and tenant isolation.
 */

beforeEach(function () {
    Mail::fake();
    $this->company = Company::factory()->create(['name' => 'Khan Mini Mart']);
    $this->owner = H::member($this->company, CompanyRole::Owner, attributes: ['name' => 'Aisha Khan']);
    $this->manager = H::member($this->company, CompanyRole::Manager, attributes: ['name' => 'Bilal Ahmed']);
    $this->shop = Branch::factory()->forCompany($this->company)->create(['name' => 'Leeds Road']);
    [$this->invitation] = H::invite($this->company, $this->owner, 'waiting@example.test');

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherOwner = H::member($this->other, CompanyRole::Owner, attributes: ['name' => 'Other Owner']);
    $this->otherStaff = H::member($this->other, CompanyRole::Staff, attributes: ['name' => 'Other Staff']);
    [$this->otherInvitation] = H::invite($this->other, $this->otherOwner, 'other-invite@example.test');
});

function portalUserRoutes(object $t): array
{
    return [
        ['get', '/app/users'],
        ['put', "/app/users/{$t->manager->id}"],
        ['post', "/app/users/{$t->manager->id}/deactivate"],
        ['post', "/app/users/{$t->manager->id}/reactivate"],
        ['delete', "/app/users/{$t->manager->id}"],
        ['post', '/app/users/invitations'],
        ['post', "/app/users/invitations/{$t->invitation->id}/resend"],
        ['delete', "/app/users/invitations/{$t->invitation->id}"],
    ];
}

test('guests are sent to the sign-in page from every route', function () {
    foreach (portalUserRoutes($this) as [$method, $url]) {
        $this->{$method}($url)->assertRedirect('/login');
    }
});

test('managers, accountants and staff get 403 on every route', function (CompanyRole $role) {
    $user = $role === CompanyRole::Manager ? $this->manager : H::member($this->company, $role);

    foreach (portalUserRoutes($this) as [$method, $url]) {
        $this->actingAs($user)->{$method}($url, ['role' => 'staff', 'name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
    }

    expect(H::membership($this->company, $this->manager))->role->toBe('manager')->is_active->toBeTruthy()
        ->and($this->invitation->fresh()->revoked_at)->toBeNull();
})->with([CompanyRole::Manager, CompanyRole::Accountant, CompanyRole::Staff]);

test('the owner sees this business\'s users, invitations, shops and the role matrix only', function () {
    $this->actingAs($this->owner)->get('/app/users')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/users/index')
        ->has('members', 2)
        ->where('members.0.name', 'Aisha Khan')
        ->where('members.0.isYou', true)
        ->where('members.1.name', 'Bilal Ahmed')
        ->has('invitations', 1)
        ->where('invitations.0.email', 'waiting@example.test')
        ->where('invitations.0.status', 'pending')
        ->missing('invitations.0.token_hash')
        ->has('branches', 1)
        ->where('branches.0.name', 'Leeds Road')
        ->where('stats.active', 2)
        ->where('stats.owners', 1)
        ->where('stats.pendingInvitations', 1)
        ->has('roles', 4)
        ->where('matrix', fn ($rows) => collect($rows)->firstWhere('key', 'users.manage')['roles'] === ['owner' => true, 'manager' => false, 'accountant' => false, 'staff' => false])
        ->where('validDays', 7));

    $this->actingAs($this->owner)->get('/app')->assertInertia(fn (Assert $page) => $page->where('abilities', fn ($abilities) => collect($abilities)->contains('users.manage')));
});

test('the owner invites, changes access, deactivates, reactivates and removes through the routes', function () {
    $this->actingAs($this->owner)->post('/app/users/invitations', ['name' => 'Cara', 'email' => 'cara@example.test', 'role' => 'staff', 'branch_id' => $this->shop->id])
        ->assertRedirect()->assertSessionHas('success');
    Mail::assertQueued(PortalInvitationMail::class, fn ($mail) => $mail->hasTo('cara@example.test'));

    $this->actingAs($this->owner)->put("/app/users/{$this->manager->id}", ['role' => 'manager', 'branch_id' => $this->shop->id])->assertSessionHasNoErrors();
    expect(H::membership($this->company, $this->manager)->branch_id)->toBe($this->shop->id);

    $this->actingAs($this->owner)->post("/app/users/{$this->manager->id}/deactivate")->assertSessionHas('success');
    $this->actingAs($this->owner)->post("/app/users/{$this->manager->id}/reactivate")->assertSessionHas('success');
    $this->actingAs($this->owner)->post("/app/users/invitations/{$this->invitation->id}/resend")->assertSessionHas('success');
    $this->actingAs($this->owner)->delete("/app/users/invitations/{$this->invitation->id}")->assertSessionHas('success');
    $this->actingAs($this->owner)->delete("/app/users/{$this->manager->id}")->assertSessionHas('success');

    expect(H::membership($this->company, $this->manager))->toBeNull()
        ->and($this->invitation->fresh()->revoked_at)->not->toBeNull();
});

test('form validation: name, email and a known role are required; the owner cannot remove themselves', function () {
    $this->actingAs($this->owner)->post('/app/users/invitations', ['name' => '', 'email' => 'nope', 'role' => 'boss'])
        ->assertSessionHasErrors(['name', 'email', 'role']);
    $this->actingAs($this->owner)->put("/app/users/{$this->manager->id}", ['role' => 'owner', 'branch_id' => $this->shop->id])
        ->assertSessionHasErrors('branch_id');
    $this->actingAs($this->owner)->delete("/app/users/{$this->owner->id}")->assertSessionHasErrors('user');

    expect(CompanyInvitation::withoutCompanyScope()->where('company_id', $this->company->id)->count())->toBe(1)
        ->and(H::membership($this->company, $this->owner))->not->toBeNull();
});

test('an owner cannot see or change another business\'s users or invitations', function () {
    $foreignShop = Branch::factory()->forCompany($this->other)->create();

    foreach ([
        ['put', "/app/users/{$this->otherStaff->id}", ['role' => 'manager']],
        ['post', "/app/users/{$this->otherStaff->id}/deactivate", []],
        ['delete', "/app/users/{$this->otherStaff->id}", []],
        ['post', "/app/users/invitations/{$this->otherInvitation->id}/resend", []],
        ['delete', "/app/users/invitations/{$this->otherInvitation->id}", []],
    ] as [$method, $url, $data]) {
        $this->actingAs($this->owner)->{$method}($url, $data)->assertNotFound();
    }

    // Nor limit one of its own users to the other business's shop.
    $this->actingAs($this->owner)->put("/app/users/{$this->manager->id}", ['role' => 'manager', 'branch_id' => $foreignShop->id])->assertSessionHasErrors('branch_id');

    expect(H::membership($this->other, $this->otherStaff))->role->toBe('staff')->is_active->toBeTruthy()
        ->and($this->otherInvitation->fresh()->revoked_at)->toBeNull()
        ->and($this->otherInvitation->fresh()->send_count)->toBe(1);

    $this->actingAs($this->owner)->get('/app/users')->assertInertia(fn (Assert $page) => $page
        ->where('members', fn ($members) => collect($members)->pluck('name')->doesntContain('Other Staff'))
        ->where('invitations', fn ($invitations) => collect($invitations)->pluck('email')->doesntContain('other-invite@example.test')));
});
