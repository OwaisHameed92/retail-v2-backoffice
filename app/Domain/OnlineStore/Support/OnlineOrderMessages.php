<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\Notifications\Support\AlertInbox;
use App\Domain\Notifications\Support\AlertLinks;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Mail\OnlineOrderMailData;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * What the online order listeners send (OS-4): the mail data for one order, the shop's order address and the portal
 * notifications. Always for a known business, run inside its scope (queue workers have no signed-in user).
 */
final class OnlineOrderMessages
{
    /** The bell entry's type (a string: online orders have no alert settings of their own). */
    public const ALERT_TYPE = 'onlineOrder';

    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * Runs the callback scoped to the order's business with the order (lines and shop loaded) and its store, or
     * does nothing when the online store is switched off or any of them is gone.
     *
     * @param  Closure(OnlineOrder, OnlineStore, Company): void  $send
     */
    public function forOrder(string $companyId, string $orderId, Closure $send): void
    {
        $company = OnlineStoreSwitch::enabled() ? Company::query()->find($companyId) : null;

        if ($company === null) {
            return;
        }

        $this->tenancy->runAs($company, function (Company $company) use ($orderId, $send): void {
            $order = OnlineOrder::query()->with(['lines', 'branch:id,name,address,town,postcode'])->where('company_id', $company->id)->find($orderId);
            $store = $order === null ? null : OnlineStore::query()->where('company_id', $company->id)->find($order->online_store_id);

            if ($order !== null && $store !== null) {
                $send($order, $store, $company);
            }
        });
    }

    /** The shop's order address: the store's order email, else the business owner's sign-in email, else the business email. */
    public function shopAddress(OnlineStore $store, Company $company): ?string
    {
        $owner = $company->owners()->orderBy('users.id')->value('users.email');

        foreach ([$store->order_email, $owner, $company->email] as $email) {
            if (is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
                return trim($email);
            }
        }

        return null;
    }

    public function mailData(OnlineOrder $order, OnlineStore $store, Company $company, ?string $replyTo, bool $forCustomer): OnlineOrderMailData
    {
        $branch = $order->branch;
        $address = array_filter([$branch->address, $branch->town, $branch->postcode], fn (?string $p) => $p !== null && trim($p) !== '');

        return new OnlineOrderMailData(
            businessName: $company->name,
            shopName: $branch->name ?? $company->name,
            shopAddress: $address === [] ? null : implode(', ', array_map('trim', $address)),
            reference: $order->reference,
            customerName: $order->customer_name,
            customerEmail: $order->customer_email,
            customerMobile: $order->customer_mobile,
            collectFrom: $order->collect_from,
            collectTo: $order->collect_to,
            lines: $order->lines->map(fn (OnlineOrderLine $line) => [
                'name' => $line->name,
                'quantity' => OrderRows::quantity($line->quantity, $line->is_weighed),
                'weighed' => $line->is_weighed,
                'unitPrice' => $line->unit_price,
                'lineTotal' => $line->line_total,
            ])->values()->all(),
            itemsTotal: $order->items_total,
            replyTo: $replyTo,
            orderUrl: $forCustomer && Route::has('store.order') ? app(StoreLinks::class)->orderPage($order) : null,
            portalUrl: $forCustomer ? null : self::portalUrl($order),
            cancelReason: $order->cancel_reason,
            companyId: $company->id,
            fulfilment: $order->fulfilment->value,
            deliveryAddress: $order->deliveryAddress(),
            deliveryFee: $order->delivery_fee,
            grandTotal: $order->grandTotal(),
            paymentLabel: match (true) {
                $order->isPaid() => 'Paid by card online',
                $order->payment_method === OnlinePaymentMethod::PayOnDelivery => 'Pay on delivery',
                default => 'Pay at the shop when you collect',
            },
            paid: $order->isPaid(),
        );
    }

    /**
     * A bell entry for every active member who runs the online store (`store.manage`) and may see the order's shop.
     * Returns how many were written.
     */
    public function notifyStaff(OnlineOrder $order, Company $company): int
    {
        $members = DB::table('company_user')->where('company_id', $company->id)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $order->branch_id))
            ->orderBy('user_id')->get(['user_id', 'role']);
        $body = implode(' · ', [
            OrderRows::shortName($order->customer_name),
            OrderRows::itemCount($order->lines).' '.(OrderRows::itemCount($order->lines) === 1 ? 'item' : 'items'),
            MoneyFormat::format($order->grandTotal()).($order->isPaid() ? ' paid' : ''),
            ($order->isDelivery() ? 'deliver ' : 'collect ').self::slotText($order).($order->isDelivery() ? ' to '.$order->delivery_postcode : ' at '.($order->branch->name ?? '')),
        ]);
        $count = 0;

        foreach ($members as $member) {
            if (CompanyRole::tryFrom((string) $member->role)?->can(Ability::StoreManage) === true) {
                AlertInbox::add($company->id, (int) $member->user_id, self::ALERT_TYPE, 'info', 'New online order '.$order->reference, $body, self::portalUrl($order));
                $count++;
            }
        }

        return $count;
    }

    public static function portalUrl(OnlineOrder $order): string
    {
        return AlertLinks::portal('/app/online-orders/'.$order->id);
    }

    /** "Mon 2 Nov, 10:00–10:30" in shop time. */
    private static function slotText(OnlineOrder $order): string
    {
        $zone = OnlineStoreSwitch::timezone();

        return $order->collect_from->setTimezone($zone)->format('D j M, H:i').'–'.$order->collect_to->setTimezone($zone)->format('H:i');
    }
}
