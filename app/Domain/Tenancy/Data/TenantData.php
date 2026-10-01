<?php

namespace App\Domain\Tenancy\Data;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\CompanyMembership;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Shapes tenants, branches, registers and members for the admin Inertia pages (camelCase keys, ISO dates).
 */
final class TenantData
{
    /**
     * @return array<string, mixed>
     */
    public static function listRow(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legalName' => $company->legal_name,
            'status' => $company->status->value,
            'branchesCount' => (int) $company->getAttribute('active_branches_count'),
            'registersCount' => (int) $company->getAttribute('active_registers_count'),
            'ownerEmail' => $company->getAttribute('owner_email'),
            'createdAt' => $company->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function company(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legalName' => $company->legal_name,
            'vatNumber' => $company->vat_number,
            'companyNumber' => $company->company_number,
            'address' => $company->address,
            'phone' => $company->phone,
            'email' => $company->email,
            'contactName' => $company->contact_name,
            'businessType' => $company->business_type?->value,
            'businessTypeLabel' => $company->business_type?->label(),
            'town' => $company->town,
            'postcode' => $company->postcode,
            'ownerName' => $company->owner_name,
            'receiptFooter' => $company->receipt_footer,
            'notes' => $company->notes,
            'status' => $company->status->value,
            'trialEndsAt' => $company->trial_ends_at?->toIso8601String(),
            'activatedAt' => $company->activated_at?->toIso8601String(),
            'suspendedAt' => $company->suspended_at?->toIso8601String(),
            'suspensionReason' => $company->suspension_reason,
            'cancelledAt' => $company->cancelled_at?->toIso8601String(),
            'cancellationReason' => $company->cancellation_reason,
            'createdAt' => $company->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function branch(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'code' => $branch->code,
            'name' => $branch->name,
            'address' => $branch->address,
            'phone' => $branch->phone,
            'vatNumber' => $branch->vat_number,
            'town' => $branch->town,
            'postcode' => $branch->postcode,
            'receiptFooter' => $branch->receipt_footer,
            'nation' => $branch->nation->value,
            'nationLabel' => $branch->nation->label(),
            'licensedHoursJson' => $branch->licensed_hours_json,
            'isDrsReturnPoint' => $branch->is_drs_return_point,
            'areaM2' => $branch->area_m2,
            'isActive' => $branch->is_active,
            'createdAt' => $branch->created_at?->toIso8601String(),
            'registers' => $branch->relationLoaded('registers')
                ? $branch->registers->map(fn (Register $register) => self::register($register))->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function register(Register $register): array
    {
        return [
            'id' => $register->id,
            'code' => $register->code,
            'name' => $register->name,
            'isMainTill' => $register->is_main_till,
            'isActive' => $register->is_active,
            'createdAt' => $register->created_at?->toIso8601String(),
        ];
    }

    /**
     * A company member; `$user->membership` is the company_user pivot.
     *
     * @return array<string, mixed>
     */
    public static function member(User $user): array
    {
        /** @var CompanyMembership $membership */
        $membership = $user->getRelation('membership');
        $role = $membership->role;
        $joined = $membership->getAttribute('created_at');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role->value,
            'roleLabel' => $role->label(),
            'isActive' => $membership->is_active,
            'isOwner' => $role === CompanyRole::Owner,
            'joinedAt' => $joined instanceof DateTimeInterface ? Carbon::instance($joined)->toIso8601String() : null,
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function roleOptions(): array
    {
        return array_map(fn (CompanyRole $role) => ['value' => $role->value, 'label' => $role->label()], CompanyRole::cases());
    }
}
