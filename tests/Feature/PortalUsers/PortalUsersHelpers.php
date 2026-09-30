<?php

namespace Tests\Feature\PortalUsers;

use App\Domain\Mail\Mailables\PortalInvitationMail;
use App\Domain\PortalUsers\Actions\InviteUser;
use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Module 4.1 test helpers. Call Mail::fake() before invite().
 */
final class PortalUsersHelpers
{
    public static function member(Company $company, CompanyRole $role = CompanyRole::Owner, ?string $branchId = null, bool $active = true, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $company->users()->attach($user->id, ['role' => $role->value, 'branch_id' => $branchId, 'is_active' => $active]);

        return $user;
    }

    /**
     * @return array{0: CompanyInvitation, 1: string} the invitation and the emailed (signed) link
     */
    public static function invite(Company $company, User $owner, string $email = 'new.person@example.test', CompanyRole $role = CompanyRole::Manager, ?string $branchId = null): array
    {
        $invitation = app(CurrentCompany::class)->runAs($company, fn () => app(InviteUser::class)->handle($company, $owner, 'New Person', $email, $role, $branchId));

        return [$invitation, self::lastLink($email)];
    }

    /** The link in the last invitation email queued to this address. */
    public static function lastLink(string $email): string
    {
        $email = strtolower(trim($email));
        $url = null;

        Mail::assertQueued(PortalInvitationMail::class, function (PortalInvitationMail $mail) use ($email, &$url) {
            if ($mail->hasTo($email)) {
                $url = $mail->data->url;
            }

            return true;
        });

        return $url ?? throw new RuntimeException("No invitation queued to {$email}");
    }

    /** @return object{role: string, branch_id: string|null, is_active: int|bool}|null */
    public static function membership(Company $company, User $user): ?object
    {
        /** @var object{role: string, branch_id: string|null, is_active: int|bool}|null $row */
        $row = $company->users()->newPivotQuery()->where('user_id', $user->id)->first(['role', 'branch_id', 'is_active']);

        return $row;
    }
}
