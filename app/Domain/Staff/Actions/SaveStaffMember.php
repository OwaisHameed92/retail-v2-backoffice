<?php

namespace App\Domain\Staff\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Staff\Models\StaffBranch;
use App\Domain\Staff\Support\StaffGuards;
use App\Domain\Staff\Support\TillPinHasher;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\TillRole;
use App\Domain\TillData\Models\TillUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a till staff member (the till's hub-owned `User`, module 4.5). The row is saved through its model,
 * so every till receives it at its next pull (HubOwnedRow). A new member needs a PIN (only its PBKDF2 hash is kept,
 * TillPinHasher); an edit never touches the PIN or fob (SetStaffPin, AssignStaffFob). The shops they work at are
 * portal-only (StaffBranch). A change that would leave no active Owner on the tills is refused.
 *
 *     app(SaveStaffMember::class)->handle($company, null, ['name' => 'Aisha', 'role_id' => $roleId, 'pin' => '4821', ...]);
 */
final class SaveStaffMember
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly StaffGuards $guards,
        private readonly TillPinHasher $hasher,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  array{name: string, role_id: string, rate_per_hour?: string|null, max_shift_hours?: string|null,
     *     is_service_staff?: bool, allow_commission?: bool, is_personal_licence_holder?: bool,
     *     simple_mode_override?: bool|null, big_text_mode?: bool, is_active?: bool, preferred_culture?: string|null,
     *     branch_ids?: list<string>, pin?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(Company $company, ?string $memberId, array $data): TillUser
    {
        return $this->tenancy->runAs($company, function () use ($memberId, $data): TillUser {
            // Checked before the transaction, so a refused PIN's audit row (StaffGuards, L5) is kept.
            if ($memberId === null) {
                $this->guards->checkPin((string) ($data['pin'] ?? ''), null);
            }

            return $this->saveInTransaction($memberId, $data);
        });
    }

    /**
     * @param  array{name: string, role_id: string, rate_per_hour?: string|null, max_shift_hours?: string|null,
     *     is_service_staff?: bool, allow_commission?: bool, is_personal_licence_holder?: bool,
     *     simple_mode_override?: bool|null, big_text_mode?: bool, is_active?: bool, preferred_culture?: string|null,
     *     branch_ids?: list<string>, pin?: string|null}  $data
     */
    private function saveInTransaction(?string $memberId, array $data): TillUser
    {
        return DB::transaction(function () use ($memberId, $data): TillUser {
            $member = $memberId === null ? new TillUser : TillUser::query()->findOrFail($memberId);
            $name = trim($data['name']);
            $role = TillRole::query()->find($data['role_id']);

            if ($role === null) {
                throw ValidationException::withMessages(['role_id' => 'Choose one of this business\'s till roles.']);
            }

            $clash = TillUser::query()->when($member->exists, fn ($q) => $q->whereKeyNot($member->id))
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();

            if ($clash) {
                throw ValidationException::withMessages(['name' => "A staff member called {$name} already exists."]);
            }

            $branchIds = array_values(array_unique($data['branch_ids'] ?? []));

            if (Branch::query()->whereIn('id', $branchIds)->count() !== count($branchIds)) {
                throw ValidationException::withMessages(['branch_ids' => 'Choose shops of this business.']);
            }

            $active = $data['is_active'] ?? true;
            $before = $member->exists ? $this->summary($member) : null;

            if ($member->exists) {
                $this->guards->keepAnOwner($member, $active && $this->guards->isOwnerRole($role->id), 'role_id');
            } else {
                $member->forceFill(['pin_hash' => $this->hasher->hash((string) $data['pin']), 'rfid' => '']);
            }

            $member->forceFill([
                'name' => $name,
                'role_id' => $role->id,
                'rate_per_hour' => Money::normalise(($data['rate_per_hour'] ?? null) ?: '0'),
                'max_shift_hours' => Money::normalise(($data['max_shift_hours'] ?? null) ?: '0', 4),
                'is_service_staff' => $data['is_service_staff'] ?? false,
                'allow_commission' => $data['allow_commission'] ?? false,
                'is_personal_licence_holder' => $data['is_personal_licence_holder'] ?? false,
                'simple_mode_override' => $data['simple_mode_override'] ?? null,
                'big_text_mode' => $data['big_text_mode'] ?? false,
                'is_active' => $active,
                'preferred_culture' => ($data['preferred_culture'] ?? null) ?: ($member->preferred_culture ?: 'en-GB'),
            ]);

            if (! $member->exists || $member->isDirty()) {
                $member->save();
            }

            $this->syncBranches($member, $branchIds);
            $this->audit->handle($before === null ? 'staff.created' : 'staff.updated', $member, $before, $this->summary($member));

            return $member;
        });
    }

    /**
     * @param  list<string>  $branchIds
     */
    private function syncBranches(TillUser $member, array $branchIds): void
    {
        StaffBranch::query()->where('till_user_id', $member->id)->whereNotIn('branch_id', $branchIds)->delete();
        $held = StaffBranch::query()->where('till_user_id', $member->id)->pluck('branch_id')->all();

        foreach (array_diff($branchIds, $held) as $branchId) {
            StaffBranch::query()->create(['till_user_id' => $member->id, 'branch_id' => $branchId]);
        }
    }

    /**
     * What the audit log keeps: never the PIN hash or the fob.
     *
     * @return array<string, mixed>
     */
    private function summary(TillUser $member): array
    {
        return [
            'name' => $member->name,
            'role_id' => $member->role_id,
            'is_active' => $member->is_active,
            'rate_per_hour' => $member->rate_per_hour,
            'branch_ids' => StaffBranch::query()->where('till_user_id', $member->id)->orderBy('branch_id')->pluck('branch_id')->all(),
        ];
    }
}
