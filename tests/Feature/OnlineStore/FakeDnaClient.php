<?php

namespace Tests\Feature\OnlineStore;

use App\Domain\OnlineStore\Payments\DnaCheckout;
use App\Domain\OnlineStore\Payments\DnaClient;
use App\Domain\OnlineStore\Payments\DnaCredentials;
use App\Domain\OnlineStore\Payments\DnaPaymentException;
use App\Domain\OnlineStore\Payments\DnaSignature;

/**
 * OS-9: DNA Payments in tests. Records the checkouts started (nothing is sent anywhere) and builds the signed
 * results DNA would post back to the store's callback.
 */
final class FakeDnaClient implements DnaClient
{
    /** @var list<array{credentials: DnaCredentials, checkout: DnaCheckout}> */
    public array $started = [];

    public bool $fail = false;

    public static function bind(): self
    {
        $fake = new self;
        app()->instance(DnaClient::class, $fake);

        return $fake;
    }

    public function checkoutUrl(DnaCredentials $credentials, DnaCheckout $checkout): string
    {
        if ($this->fail) {
            throw new DnaPaymentException('DNA is down.');
        }

        $this->started[] = ['credentials' => $credentials, 'checkout' => $checkout];

        return 'https://test-pay.dnapayments.com/checkout/?params=fake-'.$checkout->invoiceId;
    }

    public function last(): DnaCheckout
    {
        return $this->started[array_key_last($this->started)]['checkout'];
    }

    /**
     * A DNA result for an invoice, signed with the secret (DNA's own fields; `success` as JSON true / false).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function result(string $invoiceId, string $amount, string $secret, bool $success = true, array $overrides = []): array
    {
        $result = [
            'id' => 'txn-'.substr(md5($invoiceId), 0, 10), 'invoiceId' => $invoiceId, 'amount' => (float) $amount, 'currency' => 'GBP',
            'errorCode' => $success ? 0 : 1100, 'success' => $success, 'message' => $success ? 'Approved' : 'Card declined',
            ...$overrides,
        ];

        return [...$result, 'signature' => DnaSignature::sign($result, $secret)];
    }
}
