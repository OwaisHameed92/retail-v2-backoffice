<?php

namespace App\Domain\Billing\Data;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\SetupFeeTills;
use App\Domain\Billing\Support\Vat;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;

/**
 * P11: what the admin Add till / Add branch dialogs show about the setup fee of the tills being added: whether one
 * is charged (per-till plan, first setup fee already invoiced or recorded), the per-till fee, the tills the business
 * has and the tills already covered (so the dialog works out how many new tills are charged), the VAT rate and
 * whether this admin may change the amount (billing.manage). ChargeAddedTills applies the same rule.
 */
final class AddedTillFee
{
    /**
     * @return array{applies: bool, perTillFee: string, tills: int, covered: int, vatRate: string|null, canEdit: bool}
     */
    public static function for(Company $company, mixed $admin): array
    {
        $account = app(BillingAccounts::class)->for($company);
        $started = $account->setup_fee_invoiced_at !== null || $account->upfront_recorded_at !== null;
        $tills = SetupFeeTills::count($company);
        $vat = Vat::rateFor($account);

        return [
            'applies' => $started && SetupFeeTills::perTill($company),
            'perTillFee' => SetupFee::perTillFee($company, $account),
            'tills' => $tills,
            'covered' => SetupFeeTills::covered($company, $account),
            'vatRate' => Money::isZero($vat) ? null : $vat,
            'canEdit' => $admin instanceof Admin && $admin->hasAbility(AdminRole::BILLING_MANAGE),
        ];
    }
}
