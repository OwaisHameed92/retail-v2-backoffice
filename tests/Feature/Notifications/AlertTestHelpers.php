<?php

namespace Tests\Feature\Notifications;

use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Notifications\Actions\DispatchUrgentAlerts;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;

/**
 * Module 7.8 test helpers: a business with Leeds (two tills) and Bradford (one till), members, Till health alerts.
 */
trait AlertTestHelpers
{
    /** Licensed business "Khan Mini Mart" with LDS (tills 01, 02) and BRD (till 01), every licence active. */
    public function alertTenant(string $name = 'Khan Mini Mart'): Company
    {
        $company = $this->licensedTenant($name, 2, 'LDS');
        $bradford = Branch::factory()->forCompany($company)->create(['code' => 'BRD', 'name' => 'Bradford']);
        $till = Register::factory()->forBranch($bradford)->create(['code' => '01', 'name' => 'Till 1', 'is_main_till' => true]);
        Licence::factory()->forRegister($till)->create();
        Licence::withoutCompanyScope()->where('company_id', $company->id)->update(['status' => LicenceStatus::Active->value, 'expires_at' => now()->addYear()]);

        return $company;
    }

    public function till(Company $company, string $branch, string $code = '01'): Licence
    {
        return $this->licenceOf($this->registerOf($this->branchOf($company, $branch), $code));
    }

    public function member(Company $company, CompanyRole $role, ?string $branchId = null, ?string $email = null): User
    {
        $user = User::factory()->create($email !== null ? ['email' => $email] : []);
        $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true, 'branch_id' => $branchId]);

        return $user;
    }

    public function raiseAlert(Licence $licence, LicenceAlertType $type = LicenceAlertType::TillOffline, string $summary = 'Last heard from 7 Oct 2026, 07:40.'): LicenceAlert
    {
        return LicenceAlert::withoutCompanyScope()->create([
            'company_id' => $licence->company_id,
            'licence_id' => $licence->id,
            'type' => $type,
            'fingerprint' => hash('sha256', 'till-health|'.$type->value),
            'details' => ['summary' => $summary],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'count' => 1,
        ]);
    }

    public function clearAlert(LicenceAlert $alert): void
    {
        $alert->forceFill(['resolved_at' => now()])->save();
    }

    /** @return array{emailed: int, notified: int, resolved: int, muted: int} */
    public function checkAlerts(?array $companyIds = null): array
    {
        return app(DispatchUrgentAlerts::class)->handle(null, $companyIds);
    }
}
