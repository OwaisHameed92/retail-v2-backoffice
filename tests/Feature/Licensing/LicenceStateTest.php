<?php

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Queries\LicenceQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(fn () => Mail::fake());

/**
 * Each case: a name → [set-up closure(Licence, Company, Branch, Register, now), expected status, expected reason code].
 * Trial: 7 days + 3 grace. Paid grace: 7 days.
 *
 * @return array<string, array{0: Closure, 1: LicenceStatus, 2: string|null}>
 */
function licenceStateCases(): array
{
    $activate = fn (Licence $l, CarbonImmutable $at, array $extra = []) => $l->forceFill($extra + [
        'status' => LicenceStatus::Trial, 'activated_at' => $at, 'trial_ends_at' => $at->addDays(7), 'grace_days' => 3, 'device_id' => 'PC-1',
    ])->save();

    return [
        'issued, never activated' => [fn () => null, LicenceStatus::Issued, null],
        'trial, day 3' => [fn ($l, $c, $b, $r, $now) => $activate($l, $now->subDays(3)), LicenceStatus::Trial, null],
        'trial, last second' => [fn ($l, $c, $b, $r, $now) => $activate($l, $now->subDays(7)->addSecond()), LicenceStatus::Trial, null],
        'trial ended, in grace' => [fn ($l, $c, $b, $r, $now) => $activate($l, $now->subDays(8)), LicenceStatus::Grace, null],
        'trial grace over' => [fn ($l, $c, $b, $r, $now) => $activate($l, $now->subDays(10)), LicenceStatus::Expired, LicenceState::REASON_EXPIRED],
        'paid, running' => [fn ($l, $c, $b, $r, $now) => $activate($l, $now->subDays(40), ['status' => LicenceStatus::Active, 'expires_at' => $now->addDays(5), 'grace_days' => 7]), LicenceStatus::Active, null],
        'paid, expired yesterday' => [fn ($l, $c, $b, $r, $now) => $activate($l, $now->subDays(40), ['status' => LicenceStatus::Active, 'expires_at' => $now->subDay(), 'grace_days' => 7]), LicenceStatus::Grace, null],
        'paid, grace over' => [fn ($l, $c, $b, $r, $now) => $activate($l, $now->subDays(40), ['status' => LicenceStatus::Grace, 'expires_at' => $now->subDays(7), 'grace_days' => 7]), LicenceStatus::Expired, LicenceState::REASON_EXPIRED],
        'paid wins over an old trial end' => [fn ($l, $c, $b, $r, $now) => $activate($l, $now->subDays(20), ['expires_at' => $now->addDays(9), 'grace_days' => 7]), LicenceStatus::Active, null],
        'activated with no end date fails closed' => [fn ($l, $c, $b, $r, $now) => $activate($l, $now->subDay(), ['trial_ends_at' => null]), LicenceStatus::Expired, LicenceState::REASON_EXPIRED],
        'suspended by staff' => [fn ($l, $c, $b, $r, $now) => $activate($l, $now->subDay(), ['status' => LicenceStatus::Suspended, 'suspended_reason' => 'Unpaid']), LicenceStatus::Suspended, LicenceState::REASON_SUSPENDED],
        'revoked' => [fn ($l, $c, $b, $r, $now) => $activate($l, $now->subDay(), ['status' => LicenceStatus::Revoked]), LicenceStatus::Revoked, LicenceState::REASON_REVOKED],
        'revoked beats a suspended company' => [function ($l, $c, $b, $r, $now) use ($activate) {
            $activate($l, $now->subDay(), ['status' => LicenceStatus::Revoked]);
            $c->forceFill(['status' => CompanyStatus::Suspended])->save();
        }, LicenceStatus::Revoked, LicenceState::REASON_REVOKED],
        'company suspended' => [function ($l, $c, $b, $r, $now) use ($activate) {
            $activate($l, $now->subDay());
            $c->forceFill(['status' => CompanyStatus::Suspended])->save();
        }, LicenceStatus::Suspended, LicenceState::REASON_COMPANY_SUSPENDED],
        'company cancelled' => [function ($l, $c, $b, $r, $now) use ($activate) {
            $activate($l, $now->subDay());
            $c->forceFill(['status' => CompanyStatus::Cancelled])->save();
        }, LicenceStatus::Suspended, LicenceState::REASON_COMPANY_CANCELLED],
        'company deleted' => [fn ($l, $c) => $c->delete(), LicenceStatus::Suspended, LicenceState::REASON_COMPANY_CANCELLED],
        'company overdue still trades' => [function ($l, $c, $b, $r, $now) use ($activate) {
            $activate($l, $now->subDay());
            $c->forceFill(['status' => CompanyStatus::Overdue])->save();
        }, LicenceStatus::Trial, null],
        'branch inactive' => [function ($l, $c, $b, $r, $now) use ($activate) {
            $activate($l, $now->subDay());
            $b->forceFill(['is_active' => false])->save();
        }, LicenceStatus::Suspended, LicenceState::REASON_BRANCH_INACTIVE],
        'branch deleted' => [fn ($l, $c, $b) => $b->delete(), LicenceStatus::Suspended, LicenceState::REASON_BRANCH_INACTIVE],
        'till inactive' => [function ($l, $c, $b, $r, $now) use ($activate) {
            $activate($l, $now->subDay());
            $r->forceFill(['is_active' => false, 'is_main_till' => false])->save();
        }, LicenceStatus::Suspended, LicenceState::REASON_TILL_INACTIVE],
        'till inactive, never activated' => [fn ($l, $c, $b, $r) => $r->forceFill(['is_active' => false, 'is_main_till' => false])->save(), LicenceStatus::Suspended, LicenceState::REASON_TILL_INACTIVE],
        'till deleted' => [fn ($l, $c, $b, $r) => $r->delete(), LicenceStatus::Suspended, LicenceState::REASON_TILL_INACTIVE],
    ];
}

dataset('licence states', fn () => licenceStateCases());

test('the effective status', function (Closure $setUp, LicenceStatus $expected, ?string $reason) {
    $now = CarbonImmutable::parse('2027-03-10 12:00:00');
    $this->travelTo($now);

    $company = $this->licensedTenant(tills: 1);
    $licence = $this->firstLicence($company);
    $branch = Branch::withoutCompanyScope()->findOrFail($licence->branch_id);
    $register = Register::withoutCompanyScope()->findOrFail($licence->register_id);

    $setUp($licence, $company, $branch, $register, $now);

    $state = LicenceState::for(Licence::withoutCompanyScope()->findOrFail($licence->id), $now);

    expect($state->status)->toBe($expected)
        ->and($state->reasonCode)->toBe($reason)
        ->and($state->canTrade())->toBe(in_array($expected, [LicenceStatus::Trial, LicenceStatus::Active, LicenceStatus::Grace], true));

    // The admin list's SQL filter agrees: the licence shows under this status and no other.
    foreach (LicenceStatus::cases() as $status) {
        $query = LicenceQuery::admin();
        LicenceQuery::whereEffectiveStatus($query, $status, $now);

        expect($query->pluck('licences.id')->contains($licence->id))->toBe($status === $expected, "SQL filter {$status->value}");
    }
})->with('licence states');

test('the reason is readable and includes the staff reason', function () {
    $licence = $this->firstLicence($this->licensedTenant(tills: 1));
    $licence->forceFill(['status' => LicenceStatus::Suspended, 'suspended_reason' => 'Invoice INV-0042 unpaid'])->save();

    expect($licence->state()->reason)->toBe('This licence is suspended. Invoice INV-0042 unpaid.');
});

test('dates of the state come from the trial or the paid expiry', function () {
    $now = CarbonImmutable::parse('2027-03-10 12:00:00');
    $licence = $this->firstLicence($this->licensedTenant(tills: 1));
    $licence->forceFill(['status' => LicenceStatus::Trial, 'activated_at' => $now, 'trial_ends_at' => $now->addDays(7), 'grace_days' => 3])->save();

    $state = LicenceState::for($licence, $now);

    expect($state->isTrial)->toBeTrue()
        ->and($state->endsAt->toDateTimeString())->toBe('2027-03-17 12:00:00')
        ->and($state->graceEndsAt->toDateTimeString())->toBe('2027-03-20 12:00:00')
        ->and($licence->fresh()->grace_ends_at->toDateTimeString())->toBe('2027-03-20 12:00:00');
});

test('the company is read even when it is not the current tenant', function () {
    $licence = $this->firstLicence($this->licensedTenant(tills: 1));

    expect(app(CurrentCompany::class)->has())->toBeFalse()
        ->and($licence->fresh()->state()->status)->toBe(LicenceStatus::Issued)
        ->and(Company::query()->count())->toBe(1);
});
