<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\CardPaymentOutcome;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Events\OnlineOrderPlaced;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Payments\DnaCredentials;
use App\Domain\OnlineStore\Payments\DnaSignature;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DNA's server-to-server payment result for a card order (OS-9, `POST webhooks/dna/{store}`; the hospitality
 * paymentWebhook). The ONLY thing that marks an order paid: the customer's browser never does.
 *
 * The result (top level or under `paymentResult`) must carry a valid signature for the store's own client secret
 * (DnaSignature), else nothing is read from it. It is matched to the store's order by its DNA invoice id. A success
 * must be for exactly the order's grand total in the country's currency; then, once (row locked: a repeated or late
 * callback changes nothing), the order is paid and becomes new: it shows on the board and OnlineOrderPlaced sends
 * the usual emails and notifications. A success that arrives after the hold ran out still counts (the money is
 * taken), so the slot may go one over. A failure releases the slot (ReleaseUnpaidOrder) and never undoes a payment.
 * Logged without secrets or card data; audited.
 */
final class RecordCardPayment
{
    public function __construct(private readonly RecordAudit $audit, private readonly ReleaseUnpaidOrder $release) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $storeId, array $payload, ?CarbonImmutable $now = null): CardPaymentOutcome
    {
        $store = preg_match('/^[0-9A-Za-z]{26}$/', $storeId) === 1 ? OnlineStore::withoutCompanyScope()->find($storeId) : null;
        $credentials = $store === null ? null : DnaCredentials::fromStore($store);

        if ($store === null || $credentials === null) {
            return CardPaymentOutcome::UnknownStore;
        }

        $result = is_array($payload['paymentResult'] ?? null) ? $payload['paymentResult'] : $payload;
        $invoice = is_scalar($result['invoiceId'] ?? null) ? (string) $result['invoiceId'] : '';

        if (! DnaSignature::verify($result, $credentials->clientSecret)) {
            Log::warning('DNA callback refused: bad signature', ['store' => $store->id, 'invoice_id' => mb_substr($invoice, 0, 40)]);

            return CardPaymentOutcome::BadSignature;
        }

        $order = StoreScope::run($store, fn (): ?OnlineOrder => OnlineOrder::query()->where('company_id', $store->company_id)
            ->where('online_store_id', $store->id)->where('payment_invoice_id', $invoice)->first());

        if ($order === null) {
            Log::warning('DNA callback for an unknown order', ['store' => $store->id, 'invoice_id' => mb_substr($invoice, 0, 40)]);

            return CardPaymentOutcome::UnknownOrder;
        }

        if (! filter_var($result['success'], FILTER_VALIDATE_BOOLEAN)) {
            $message = is_scalar($result['message'] ?? null) ? (string) $result['message'] : 'Payment declined';
            $released = $this->release->handle($store, $order->id, 'Card payment failed: '.$message);
            Log::info('DNA payment failed', ['order' => $order->reference, 'error_code' => is_scalar($result['errorCode']) ? $result['errorCode'] : null]);

            return $released ? CardPaymentOutcome::Failed : CardPaymentOutcome::Duplicate;
        }

        return $this->paid($store, $order->id, $result, ($now ?? CarbonImmutable::now())->utc());
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function paid(OnlineStore $store, string $orderId, array $result, CarbonImmutable $now): CardPaymentOutcome
    {
        $amount = self::amount($result['amount']);
        $currency = strtoupper(is_scalar($result['currency']) ? (string) $result['currency'] : '');
        $transaction = is_scalar($result['id']) ? mb_substr((string) $result['id'], 0, 100) : null;

        [$outcome, $order, $from] = StoreScope::run($store, fn (): array => DB::transaction(function () use ($store, $orderId, $amount, $currency, $transaction, $now): array {
            $order = OnlineOrder::query()->where('company_id', $store->company_id)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $from = $order->status;

            if ($order->isPaid() || ! $from->isPaymentPending()) {
                return [CardPaymentOutcome::Duplicate, $order, $from];
            }

            if ($amount === null || ! Money::equals($amount, $order->grandTotal()) || $currency !== app(Country::class)->currency()) {
                return [CardPaymentOutcome::AmountMismatch, $order, $from];
            }

            $order->forceFill([
                'status' => OnlineOrderStatus::New, 'paid_at' => $now, 'paid_amount' => $amount, 'payment_reference' => $transaction,
                'payment_error' => null, 'payment_expires_at' => null,
            ])->save();

            return [CardPaymentOutcome::Paid, $order, $from];
        }));

        if ($outcome === CardPaymentOutcome::AmountMismatch) {
            Log::error('DNA callback amount or currency mismatch', ['order' => $order->reference, 'expected' => $order->grandTotal(), 'paid' => $amount, 'currency' => $currency]);
        }

        if ($outcome !== CardPaymentOutcome::Paid) {
            return $outcome;
        }

        $this->audit->handle('online_order.paid', $order, ['status' => $from->value], [
            'status' => OnlineOrderStatus::New->value, 'paid_amount' => $order->paid_amount, 'payment_reference' => $order->payment_reference,
        ], ['number' => $order->reference], companyId: $store->company_id);
        Log::info('DNA payment captured', ['order' => $order->reference]);
        OnlineOrderPlaced::dispatch(StoreScope::run($store, fn (): OnlineOrder => $order->load(['lines', 'shop.branch', 'branch'])));

        return $outcome;
    }

    /** "12.50" from DNA's amount (a number or numeric string), or null when it is not one. */
    private static function amount(mixed $value): ?string
    {
        try {
            return is_numeric($value) ? Money::normalise((string) $value, 2) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
