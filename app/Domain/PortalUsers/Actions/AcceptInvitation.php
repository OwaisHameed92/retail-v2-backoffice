<?php

namespace App\Domain\PortalUsers\Actions;

use App\Domain\PortalUsers\Enums\InvitationStatus;
use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\PortalUsers\Support\MemberAccess;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * The invitee accepts (module 4.1).
 *
 * - No account for the invited email: one is created with the name and password they chose (the email is verified:
 *   they opened the link sent to it). They must not be signed in as someone else.
 * - An account exists: they must be signed in as it (their password never goes through this screen).
 *
 * They become a member with the invited role and shop (a deactivated membership is reactivated with them). The link
 * then stops working. The token is checked again here, under a lock, whatever the screen showed.
 */
class AcceptInvitation
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        CompanyInvitation $invitation,
        #[SensitiveParameter] string $token,
        ?User $signedIn,
        ?string $name = null,
        #[SensitiveParameter] ?string $password = null,
    ): User {
        return DB::transaction(function () use ($invitation, $token, $signedIn, $name, $password) {
            /** @var CompanyInvitation $locked */
            $locked = CompanyInvitation::withoutCompanyScope()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            $company = Company::query()->findOrFail($locked->company_id);

            if (! $locked->matchesToken($token) || $locked->status() !== InvitationStatus::Pending || ! $company->status->allowsPortalAccess()) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is no longer valid. Ask the business owner to send a new one.']);
            }

            $user = User::query()->where('email', $locked->email)->first();
            $isNew = $user === null;

            if ($signedIn !== null && ($isNew || ! $signedIn->is($user))) {
                throw ValidationException::withMessages(['invitation' => "You are signed in as {$signedIn->email}. This invitation is for {$locked->email}: sign out first."]);
            }

            if ($user === null) {
                if ($password === null || $password === '') {
                    throw ValidationException::withMessages(['password' => 'Choose a password.']);
                }

                $user = User::query()->create([
                    'name' => trim((string) $name) !== '' ? trim((string) $name) : $locked->name,
                    'email' => $locked->email,
                    'password' => $password,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            } elseif ($signedIn === null) {
                throw ValidationException::withMessages(['invitation' => "Sign in as {$locked->email} to accept this invitation."]);
            }

            $membership = MemberAccess::membership($company, $user);
            $pivot = ['role' => $locked->role->value, 'branch_id' => $locked->branch_id, 'is_active' => true];

            if ($membership === null) {
                $company->users()->attach($user->getKey(), $pivot);
            } elseif (! (bool) $membership->is_active) {
                $company->users()->updateExistingPivot($user->getKey(), $pivot);
            }

            $locked->accepted_at = now();
            $locked->accepted_user_id = $user->getKey();
            $locked->save();

            $this->audit->handle('company.invitation_accepted', $locked, null, [
                'role' => $locked->role->value,
                'branch_id' => $locked->branch_id,
            ], [
                'email' => $locked->email,
                'new_account' => $isNew,
                'already_member' => $membership !== null && (bool) $membership->is_active,
            ], $user, $company->getKey());

            return $user;
        });
    }
}
