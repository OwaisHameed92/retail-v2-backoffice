<?php

namespace App\Domain\OnlineStore\Payments;

use Illuminate\Container\Attributes\Bind;

/**
 * DNA Payments' hosted checkout (OS-9). The real client (HttpDnaClient) asks DNA for a token bound to this one payment
 * and builds the checkout address; tests bind a fake. The result comes back by DNA's callback (RecordCardPayment),
 * checked with DnaSignature, never by the customer's browser.
 */
#[Bind(HttpDnaClient::class)]
interface DnaClient
{
    /**
     * The address of DNA's payment page for this payment.
     *
     * @throws DnaPaymentException when DNA cannot be reached or refuses
     */
    public function checkoutUrl(DnaCredentials $credentials, DnaCheckout $checkout): string;
}
