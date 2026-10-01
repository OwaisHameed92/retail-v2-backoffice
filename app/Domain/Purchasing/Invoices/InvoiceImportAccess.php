<?php

namespace App\Domain\Purchasing\Invoices;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Support\AiGate;
use App\Domain\Ai\Support\AiPlan;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Purchasing\Queries\PurchasingPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may do what with invoice import (module 6.5). The routes need `purchasing.manage`; on top of that:
 *
 * - The company's plan must have `assist_invoice_scan` (the till's feature name), else the screen explains and every
 *   write is refused (403). Reading by the model also needs the AI gate (key, switch, budget): without it the screen
 *   offers entering the invoice by hand only.
 * - A one-shop user imports for their own shop and sees only its imports; ordering (a head-office order) and cost
 *   price updates (products every shop shares) need a user of every shop, and catalogue.manage for costs.
 */
final class InvoiceImportAccess
{
    public static function inPlan(Company $company): bool
    {
        return AiPlan::for($company)?->hasFeature(Feature::AssistInvoiceScan) === true;
    }

    /**
     * Whether the model can read a document now, and if not why (safe to show).
     *
     * @return array{available: bool, message: string|null}
     */
    public static function reader(User $user, Company $company): array
    {
        try {
            app(AiGate::class)->ensureAvailable(AiContext::forUser($user, $company, AiFeature::InvoiceImport));

            return ['available' => true, 'message' => null];
        } catch (AiUnavailable $e) {
            return ['available' => false, 'message' => $e->getMessage()];
        } catch (AiAccessDenied) {
            return ['available' => false, 'message' => 'You are not a member of this business.'];
        }
    }

    public static function canOrder(): bool
    {
        return PurchasingPage::canManage();
    }

    public static function canUpdateCosts(): bool
    {
        $tenancy = app(CurrentCompany::class);

        return $tenancy->can(Ability::CatalogueManage) && $tenancy->restrictedBranchId() === null;
    }

    /**
     * Imports this user may see: all of the company's, or a one-shop user's shop only.
     *
     * @return Builder<InvoiceImport>
     */
    public static function visible(): Builder
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return InvoiceImport::query()->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted));
    }
}
