<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * What changing a business's plan does (PlanChangePlanner, no writes): the facts ChangeBusinessPlan applies and the
 * preview and the email put into words. Money is net unless named gross; dates are calendar days unless named "at".
 */
final readonly class PlanChangePlan
{
    /**
     * @param  list<Feature>  $gained
     * @param  list<Feature>  $lost
     * @param  list<array{name: string, features: list<Feature>}>  $customShops  Shops with their own features (kept).
     * @param  list<Licence>  $setupLicences  Tills a "Setup fee (added tills)" invoice is for (held until paid).
     * @param  list<Licence>  $paidLicences  Live licences with a paid date ahead (moved to the first period end).
     * @param  list<Invoice>  $openSetupInvoices  Setup fee invoices still unpaid (they stay owed).
     * @param  list<Invoice>  $openPeriodInvoices  Monthly or yearly invoices still open (they stay owed).
     */
    public function __construct(
        public Company $company,
        public BillingAccount $account,
        public ?Plan $from,
        public Plan $to,
        public CarbonImmutable $today,
        public bool $samePlan,
        public bool $manual,
        // Features
        public array $gained,
        public array $lost,
        public array $customShops,
        public int $followingShops,
        // Setup fee
        public int $tills,
        public int $coveredTills,
        public int $uncoveredTills,
        public bool $perTill,
        public ?string $suggestedSetupFee,
        public ?string $setupFee,
        public ?InvoiceKind $setupKind,
        public array $setupLicences,
        public string $vatRate,
        public bool $setupSettledAfter,
        // Recurring
        public BillingCycle $cycle,
        public string $oldRecurring,
        public string $newRecurring,
        public ?string $newUnitPrice,
        public string $unitLabel,
        public bool $oldRecurs,
        public bool $newRecurs,
        public bool $keepsOwnPrices,
        public bool $clearsOwnPrices,
        public bool $directDebit,
        public bool $switchesToDirectDebit,
        public bool $mandateUsable,
        public bool $startsMandateSetup,
        public ?CarbonImmutable $mandateDeadlineAt,
        public bool $liveSubscription,
        public CarbonImmutable $nextPeriodStart,
        // The first period (setup only → recurring, with paid tills)
        public bool $firstPeriodNow,
        public ?CarbonImmutable $periodEnd,
        public ?string $firstPeriodGross,
        public int $firstPeriodTills,
        public ?CarbonImmutable $firstPeriodDue,
        public array $paidLicences,
        // Licences
        public bool $fullTermNow,
        public ?CarbonImmutable $fullTermUntil,
        public int $oldGraceDays,
        public int $newGraceDays,
        public int $liveLicences,
        // Already open
        public array $openSetupInvoices,
        public array $openPeriodInvoices,
    ) {}

    /** A setup fee invoice will be created (a charge above 0). */
    public function chargesSetupFee(): bool
    {
        return $this->setupKind !== null && $this->setupFee !== null && bccomp($this->setupFee, '0', 2) > 0;
    }

    /** The admin waives the setup fee the new plan would charge (0 entered). */
    public function waivesSetupFee(): bool
    {
        return $this->setupKind !== null && $this->setupFee !== null && bccomp($this->setupFee, '0', 2) === 0;
    }

    /** From a plan with nothing recurring to one that recurs. */
    public function startsRecurring(): bool
    {
        return ! $this->oldRecurs && $this->newRecurs;
    }

    /** From a recurring plan to one with nothing recurring. */
    public function stopsRecurring(): bool
    {
        return $this->oldRecurs && ! $this->newRecurs;
    }
}
