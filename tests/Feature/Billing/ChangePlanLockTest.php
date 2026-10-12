<?php

use App\Domain\Billing\Actions\ChangeBusinessPlan;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\ChangePlanHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Audit BILL-10 (docs/audit/2026-10-11-billing.md): Change plan works its plan out again once it holds the billing
 * lock. Two confirms at the same moment: the one that gets the lock second is refused, so the change (its setup fee
 * or first-period invoice) is applied once. The race is staged by letting the other confirm run the moment this one
 * takes the lock (on the same connection, as two processes would be serialised by the lock row).
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class, ChangePlanHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->fakeGoCardless();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
});

/** Runs `$other` once, right after the change being tested takes the billing lock (a concurrent confirm got there first). */
function cpLockRace(callable $other): void
{
    $base = DB::transactionLevel();
    $done = false;

    BillingAccount::retrieved(function () use (&$done, $base, $other) {
        if ($done || DB::transactionLevel() <= $base) {
            return;
        }

        $done = true;
        $other();
    });
}

function cpLockRefusal(Company $company, Plan $to): string
{
    try {
        app(ChangeBusinessPlan::class)->handle($company, $to);
    } catch (ValidationException $exception) {
        return (string) collect($exception->errors())->flatten()->first();
    }

    throw new RuntimeException('The second confirm was applied.');
}

test('two confirms of the same change at once: the second is refused under the lock', function () {
    $company = $this->cpOnboarded(PlanBillingType::RecurringOnly, '0.00', '25.00');
    $to = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '500.00', '12.00');
    $applied = [];
    cpLockRace(function () use ($company, $to, &$applied) {
        $applied = app(ChangeBusinessPlan::class)->handle(Company::query()->findOrFail($company->id), $to);
    });

    $message = cpLockRefusal($company, $to);

    expect($message)->toBe("{$company->name} is already on {$to->name}.")
        // The other confirm made its one setup fee invoice; this one would have made a second.
        ->and($applied['setupInvoice'])->not->toBeNull();
});

test('the plan changed to another plan between the preview and the confirm: refused, preview again', function () {
    $company = $this->cpOnboarded(PlanBillingType::RecurringOnly, '0.00', '25.00');
    $to = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '500.00', '12.00');
    $elsewhere = $this->cpPlan('Monthly plus', PlanBillingType::RecurringOnly, '0.00', '30.00');
    cpLockRace(fn () => app(ChangeBusinessPlan::class)->handle(Company::query()->findOrFail($company->id), $elsewhere));

    expect(cpLockRefusal($company, $to))->toContain('plan changed while this was being confirmed');
});

test('without a race the change applies once, and confirming it again is refused as before', function () {
    $company = $this->cpOnboarded(PlanBillingType::RecurringOnly, '0.00', '25.00');
    $to = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '500.00', '12.00');

    $result = app(ChangeBusinessPlan::class)->handle($company, $to);

    expect($result['setupInvoice'])->not->toBeNull()
        ->and(cpLockRefusal($company->refresh(), $to))->toBe("{$company->name} is already on {$to->name}.")
        ->and($this->invoicesOfKind($company, InvoiceKind::SetupFee))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'billing.plan_changed')->count())->toBe(1);
});

test('PK: the second of two confirms is refused under the lock', function () {
    config(['country.code' => 'PK', 'billing.vat.enabled' => false]);
    app()->forgetInstance(Country::class);
    $company = $this->cpOnboarded(PlanBillingType::RecurringOnly, '0.00', '2500.00', method: PaymentMethod::JazzCash);
    $to = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '50000.00', '2000.00');
    cpLockRace(fn () => app(ChangeBusinessPlan::class)->handle(Company::query()->findOrFail($company->id), $to));

    expect(cpLockRefusal($company, $to))->toBe("{$company->name} is already on {$to->name}.")
        ->and($this->gc->calls)->toBe([]);
})->group('country-pk');
