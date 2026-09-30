<?php

use App\Domain\Mail\Mailables\PortalInvitationMail;
use App\Domain\PortalUsers\Actions\InviteUser;
use App\Domain\PortalUsers\Actions\ResendInvitation;
use App\Domain\PortalUsers\Actions\RevokeInvitation;
use App\Domain\PortalUsers\Enums\InvitationStatus;
use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\PortalUsers\PortalUsersHelpers as H;

/*
 * Module 4.1: InviteUser, ResendInvitation, RevokeInvitation.
 */

beforeEach(function () {
    Mail::fake();
    $this->company = Company::factory()->create(['name' => 'Khan Mini Mart']);
    $this->owner = H::member($this->company, CompanyRole::Owner, attributes: ['name' => 'Aisha Khan']);
    $this->shop = Branch::factory()->forCompany($this->company)->create(['name' => 'Leeds Road']);
});

test('inviting stores only a token hash, emails a signed 7-day link and audits', function () {
    $this->freezeSecond();
    [$invitation, $link] = H::invite($this->company, $this->owner, 'Bilal@Example.test ', CompanyRole::Manager, $this->shop->id);

    expect($invitation->email)->toBe('bilal@example.test')
        ->and($invitation->role)->toBe(CompanyRole::Manager)
        ->and($invitation->branch_id)->toBe($this->shop->id)
        ->and($invitation->expires_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($invitation->status())->toBe(InvitationStatus::Pending)
        ->and(strlen($invitation->token_hash))->toBe(64)
        ->and($invitation->toArray())->not->toHaveKey('token_hash');

    $token = explode('/', parse_url($link, PHP_URL_PATH))[4];
    expect($link)->toContain('signature=')->toContain('expires=')
        ->and($invitation->matchesToken($token))->toBeTrue()
        ->and($invitation->token_hash)->not->toBe($token);

    Mail::assertQueued(PortalInvitationMail::class, fn (PortalInvitationMail $mail) => $mail->hasTo('bilal@example.test')
        && $mail->data->businessName === 'Khan Mini Mart'
        && $mail->data->branchName === 'Leeds Road'
        && $mail->data->inviterName === 'Aisha Khan');

    $audit = AuditLog::query()->where('action', 'company.user_invited')->sole();
    expect($audit->company_id)->toBe($this->company->id)
        ->and(json_encode($audit->toArray()))->not->toContain($token);
});

test('the invitation email renders the business, role, shop and link', function () {
    H::invite($this->company, $this->owner, 'bilal@example.test', CompanyRole::Staff, $this->shop->id);

    Mail::assertQueued(PortalInvitationMail::class, function (PortalInvitationMail $mail) {
        $html = (string) $mail->render();

        return str_contains($html, 'Khan Mini Mart') && str_contains($html, 'Staff') && str_contains($html, 'Leeds Road')
            && str_contains($html, e($mail->data->url)) && $mail->subjectLine() === "You're invited to Khan Mini Mart on Switch & Save";
    });
});

test('someone who is already a user, deactivated or invited cannot be invited again', function () {
    $active = H::member($this->company, CompanyRole::Staff, attributes: ['email' => 'active@example.test']);
    H::member($this->company, CompanyRole::Staff, active: false, attributes: ['email' => 'off@example.test']);
    H::invite($this->company, $this->owner, 'waiting@example.test');

    foreach (['active@example.test' => 'already a user', 'off@example.test' => 'Reactivate', 'waiting@example.test' => 'Resend it'] as $email => $message) {
        expect(fn () => app(InviteUser::class)->handle($this->company, $this->owner, 'X', $email, CompanyRole::Staff))
            ->toThrow(ValidationException::class, $message);
    }

    expect(CompanyInvitation::withoutCompanyScope()->count())->toBe(1)
        ->and($active->exists)->toBeTrue();
});

test('someone with an account in another business can be invited', function () {
    $elsewhere = H::member(Company::factory()->create(), CompanyRole::Owner, attributes: ['email' => 'elsewhere@example.test']);

    [$invitation] = H::invite($this->company, $this->owner, $elsewhere->email);

    expect($invitation->exists)->toBeTrue();
});

test('shop rules: an owner is never limited, and the shop must be an open shop of this business', function () {
    $closed = Branch::factory()->forCompany($this->company)->create(['is_active' => false]);
    $foreign = Branch::factory()->forCompany(Company::factory()->create())->create();
    $invite = fn (CompanyRole $role, string $branch) => app(InviteUser::class)->handle($this->company, $this->owner, 'X', 'x@example.test', $role, $branch);

    expect(fn () => $invite(CompanyRole::Owner, $this->shop->id))->toThrow(ValidationException::class, 'An owner always sees every shop')
        ->and(fn () => $invite(CompanyRole::Manager, $closed->id))->toThrow(ValidationException::class, 'open shops')
        ->and(fn () => $invite(CompanyRole::Manager, $foreign->id))->toThrow(ValidationException::class, 'open shops')
        ->and(CompanyInvitation::withoutCompanyScope()->count())->toBe(0);
});

test('resending rotates the token, restarts the 7 days and kills the old link', function () {
    $this->freezeSecond();
    [$invitation, $oldLink] = H::invite($this->company, $this->owner);
    $oldHash = $invitation->token_hash;
    $this->travel(6)->days();

    $sent = app(ResendInvitation::class)->handle($this->company, $invitation);

    expect($sent->token_hash)->not->toBe($oldHash)
        ->and($sent->send_count)->toBe(2)
        ->and($sent->expires_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'company.invitation_resent')->count())->toBe(1);

    Mail::assertQueuedCount(2);
    $this->get($oldLink)->assertInertia(fn ($page) => $page->component('auth/accept-invitation')->where('state', 'invalid'));
});

test('an expired invitation can be resent; revoked and accepted ones cannot', function () {
    [$invitation] = H::invite($this->company, $this->owner);
    $this->travel(8)->days();
    expect($invitation->status())->toBe(InvitationStatus::Expired);

    $resent = app(ResendInvitation::class)->handle($this->company, $invitation);
    expect($resent->status())->toBe(InvitationStatus::Pending);

    app(RevokeInvitation::class)->handle($this->company, $invitation);

    expect(fn () => app(ResendInvitation::class)->handle($this->company, $invitation))->toThrow(ValidationException::class, 'revoked')
        ->and(fn () => app(RevokeInvitation::class)->handle($this->company, $invitation))->toThrow(ValidationException::class, 'already revoked');
});

test('revoking closes the link for good and is audited', function () {
    [$invitation, $link] = H::invite($this->company, $this->owner);

    $revoked = app(RevokeInvitation::class)->handle($this->company, $invitation);

    expect($revoked->status())->toBe(InvitationStatus::Revoked)
        ->and(AuditLog::query()->where('action', 'company.invitation_revoked')->where('company_id', $this->company->id)->count())->toBe(1);
    $this->get($link)->assertInertia(fn ($page) => $page->where('state', 'revoked')->where('businessName', 'Khan Mini Mart'));
});

test('an action never touches another business\'s invitation', function () {
    [$invitation] = H::invite($this->company, $this->owner);
    $other = Company::factory()->create();

    expect(fn () => app(RevokeInvitation::class)->handle($other, $invitation))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ResendInvitation::class)->handle($other, $invitation))->toThrow(ModelNotFoundException::class)
        ->and($invitation->fresh()->status())->toBe(InvitationStatus::Pending);
});
