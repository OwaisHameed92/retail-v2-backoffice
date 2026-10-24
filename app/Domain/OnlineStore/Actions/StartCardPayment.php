<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Payments\DnaCheckout;
use App\Domain\OnlineStore\Payments\DnaClient;
use App\Domain\OnlineStore\Payments\DnaCredentials;
use App\Domain\OnlineStore\Payments\DnaPaymentException;
use App\Domain\OnlineStore\Support\StoreLinks;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Sends a card order's customer to DNA Payments' hosted checkout (OS-9; the hospitality PublicOrderingController's
 * paymentCheckout): the amount is the stored order's grand total (never the request's), in the country's currency,
 * to the store's own DNA account. DNA posts the result to `webhooks.dna` (RecordCardPayment) and sends the customer
 * back to the order page (`payment=failed` when it did not go through). When DNA cannot start the payment the order
 * gives its slot back (ReleaseUnpaidOrder) and the customer is asked to try again or pay another way.
 */
final class StartCardPayment
{
    public function __construct(
        private readonly DnaClient $dna,
        private readonly StoreLinks $links,
        private readonly ReleaseUnpaidOrder $release,
    ) {}

    /**
     * @return string DNA's payment page address
     *
     * @throws ValidationException
     */
    public function handle(OnlineStore $store, OnlineOrder $order, ?CarbonImmutable $now = null): string
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $credentials = DnaCredentials::fromStore($store);
        $waiting = $order->status === OnlineOrderStatus::AwaitingPayment && $order->payment_method === OnlinePaymentMethod::CardOnline
            && $order->payment_invoice_id !== null && $order->payment_expires_at?->greaterThan($now) === true;

        if ($credentials === null || ! $waiting) {
            $this->release->handle($store, $order->id, 'Card payment could not be started');

            throw ValidationException::withMessages(['paymentMethod' => 'Card payment is not available right now. Choose another way to pay.']);
        }

        $business = Company::query()->whereKey($store->company_id)->value('name');

        try {
            return $this->dna->checkoutUrl($credentials, new DnaCheckout(
                invoiceId: (string) $order->payment_invoice_id,
                amount: $order->grandTotal(),
                currency: app(Country::class)->currency(),
                description: trim($business.' online order '.$order->reference),
                returnUrl: $this->links->orderPage($order),
                failureReturnUrl: $this->links->orderPage($order, ['payment' => 'failed']),
                callbackUrl: route('webhooks.dna', ['store' => $store->id]),
                customerEmail: $order->customer_email,
            ));
        } catch (DnaPaymentException) {
            $this->release->handle($store, $order->id, 'DNA could not start the card payment');

            throw ValidationException::withMessages(['paymentMethod' => 'We could not start the card payment. Try again, or choose another way to pay.']);
        }
    }
}
