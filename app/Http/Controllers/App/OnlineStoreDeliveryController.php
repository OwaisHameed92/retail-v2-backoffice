<?php

namespace App\Http\Controllers\App;

use App\Domain\OnlineStore\Actions\SaveDeliverySettings;
use App\Domain\OnlineStore\Actions\SaveDnaCredentials;
use App\Domain\OnlineStore\Actions\SavePaymentSettings;
use App\Domain\OnlineStore\Queries\DeliveryPaymentsPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\OnlineStore\SaveDeliverySettingsRequest;
use App\Http\Requests\App\OnlineStore\SavePaymentSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The online store's "Delivery & payments" tab (OS-9, `store.manage`): local delivery per collection shop, the ways
 * to pay per method, and the store's DNA Payments account (card online). A one-shop user sees and changes their own
 * shop's delivery only.
 */
class OnlineStoreDeliveryController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(): Response
    {
        return Inertia::render('app/online-store/delivery', DeliveryPaymentsPage::for($this->tenancy->require(), $this->tenancy->restrictedBranchId()));
    }

    public function shop(SaveDeliverySettingsRequest $request, string $branch, SaveDeliverySettings $save): RedirectResponse
    {
        $shop = $save->handle($this->tenancy->require(), $branch, $request->settings());
        $name = $shop->branch->name;

        return back()->with('success', $shop->delivery_enabled ? "{$name} saved. Customers in its area can choose delivery." : "{$name} saved. It does not deliver.");
    }

    public function payments(SavePaymentSettingsRequest $request, SavePaymentSettings $save): RedirectResponse
    {
        $save->handle($this->tenancy->require(), $request->only(['collection', 'delivery']));

        return back()->with('success', 'Ways to pay saved.');
    }

    public function dna(SavePaymentSettingsRequest $request, SaveDnaCredentials $save): RedirectResponse
    {
        $store = $save->handle($this->tenancy->require(), $request->only(['client_id', 'client_secret', 'terminal_id', 'test_mode']));

        return back()->with('success', $store->dna_test_mode
            ? 'DNA Payments details saved in test mode: no real money is taken until you switch test mode off.'
            : 'DNA Payments details saved. Turn on card online under Ways to pay.');
    }

    public function removeDna(SavePaymentSettingsRequest $request, SaveDnaCredentials $save): RedirectResponse
    {
        $save->remove($this->tenancy->require());

        return back()->with('success', 'DNA Payments details removed. Customers can no longer pay by card online.');
    }
}
