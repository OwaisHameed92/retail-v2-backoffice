<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Basket\PriceBasket;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Queries\StorefrontShops;
use App\Domain\OnlineStore\Support\OnlineStoreAddOn;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\StoreRateLimits;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StoreOrderPage;
use App\Http\Support\Store\StorePages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /o/{order}` (audit OSA-6, OSA-22): the customer's order page by a link signed over the order id only, so it
 * keeps working when the store goes offline or changes its address (StoreLinks::orderPage). The link expires
 * `online_store.order_link_days` after the slot ("ask the shop to send it again": the portal can resend it). Refused
 * (404) once the online store is switched off on the server or the business's add-on is off, or for a business that
 * may no longer use the portal.
 */
class StoreOrderLinkController extends Controller
{
    public function __invoke(Request $request, StorefrontShops $shops, PriceBasket $pricer, string $order): Response
    {
        if (! URL::hasCorrectSignature($request)) {
            return StorePages::error($request, 403, null, ['This link is not valid', 'Open the order from the link in your email, or ask the shop for help.']);
        }

        $found = OnlineOrder::withoutCompanyScope()->whereKey($order)->first();
        $store = $found === null ? null : OnlineStore::withoutCompanyScope()->whereKey($found->online_store_id)->where('company_id', $found->company_id)->first();
        $company = $store === null ? null : Company::query()->find($store->company_id);

        if ($found === null || $store === null || $company === null || ! OnlineStoreSwitch::enabled() || ! OnlineStoreAddOn::isOn($company, $store)
            || ! $company->status->allowsPortalAccess() || ! $request->hasSession()) {
            return StorePages::error($request, 404);
        }

        $context = new StoreContext($store->setRelation('company', $company), $shops->handle($store), $request->session(), $pricer);
        app()->instance(StoreContext::class, $context);
        $request->attributes->set(StoreContext::class, $context);
        $request->attributes->set(StoreRateLimits::STORE_ATTRIBUTE, $store->id);
        URL::defaults(['slug' => $store->slug]);
        StorePages::share($context);

        if (! URL::signatureHasNotExpired($request)) {
            return StorePages::error($request, 403, $context, ['This link has expired', 'For your privacy, order links stop working a while after the order. Ask the shop to send it again.']);
        }

        $response = StoreOrderPage::render($request, $context, $found);
        // OSA-9: the order page shows the customer's details: never indexed.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
