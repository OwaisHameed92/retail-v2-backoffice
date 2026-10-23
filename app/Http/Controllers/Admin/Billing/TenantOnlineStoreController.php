<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Billing\Support\BillingFormat;
use App\Domain\OnlineStore\Actions\SetOnlineStoreAddOn;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Billing\OnlineStoreAddOnRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Admin → business → Billing → "Online store add-on" (OS-6): switch it on or off, its monthly price (a line on the
 * monthly invoice) and the store's template.
 * `billing.manage` (route middleware and the request); 404 where the country has no online stores.
 */
class TenantOnlineStoreController extends Controller
{
    public function update(OnlineStoreAddOnRequest $request, Company $company, SetOnlineStoreAddOn $set): RedirectResponse
    {
        $result = $set->handle($company, $request->toInput());
        $store = $result['store'];
        $price = BillingFormat::money(OnlineStoreSwitch::monthlyPrice($store->monthly_price));

        $message = $store->addon_on
            ? "Online store add-on on for {$company->name}: {$price} a month before VAT, {$store->template->label()} template. It is a line on the monthly invoice from the next one, and the Direct Debit follows."
            : "Online store add-on off for {$company->name}. It is not charged from the next invoice, and the store is closed (its settings are kept).";

        if ($result['warning'] !== null) {
            $message .= ' '.$result['warning'];
        }

        return back()->with('success', $message);
    }
}
