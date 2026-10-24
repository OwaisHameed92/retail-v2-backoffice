<?php

namespace App\Domain\OnlineStore\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DNA Payments' hosted checkout over HTTP, the same flow as the DNA PHP SDK the hospitality project uses (DnaGateway):
 *
 *   1. `POST oauth2/token` (form): a token bound to THIS payment (invoice id, amount, currency, terminal), scopes
 *      `payment integration_hosted`.
 *   2. The checkout address `{pay}/checkout/?params=…&data=…`: the payment (with the token) and the page settings,
 *      each JSON → lz-string (encoded URI component) → base64, exactly as the SDK's `generateUrl`.
 *
 * The return and callback addresses go inside `paymentSettings` (returnUrl, failureReturnUrl, callbackUrl,
 * failureCallbackUrl): DNA's hosted page ignores the SDK README's top-level postLink / backLink. Nothing secret is
 * logged: only the invoice id, test mode and DNA's status.
 */
final class HttpDnaClient implements DnaClient
{
    private const AUTH = ['live' => 'https://oauth.dnapayments.com/oauth2/token', 'test' => 'https://test-oauth.dnapayments.com/oauth2/token'];

    private const PAY = ['live' => 'https://pay.dnapayments.com', 'test' => 'https://test-pay.dnapayments.com'];

    /** How long DNA's result screen waits before sending the customer back (its default is 30 s). */
    private const AUTO_REDIRECT_MS = 5000;

    public function checkoutUrl(DnaCredentials $credentials, DnaCheckout $checkout): string
    {
        $env = $credentials->testMode ? 'test' : 'live';
        $amount = (float) $checkout->amount;

        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post(self::AUTH[$env], [
                'grant_type' => 'client_credentials',
                'scope' => 'payment integration_hosted',
                'client_id' => $credentials->clientId,
                'client_secret' => $credentials->clientSecret,
                'terminal' => $credentials->terminalId,
                'invoiceId' => $checkout->invoiceId,
                'amount' => $amount,
                'currency' => $checkout->currency,
                'paymentFormURL' => self::PAY[$env].'/checkout/',
            ]);
        } catch (Throwable $e) {
            Log::error('DNA checkout: auth request failed', ['invoice_id' => $checkout->invoiceId, 'test_mode' => $credentials->testMode, 'error' => class_basename($e)]);

            throw new DnaPaymentException('DNA could not be reached.', previous: $e);
        }

        $token = $response->json();

        if (! $response->successful() || ! is_array($token) || ! isset($token['access_token'])) {
            Log::error('DNA checkout: auth refused', ['invoice_id' => $checkout->invoiceId, 'test_mode' => $credentials->testMode, 'status' => $response->status()]);

            throw new DnaPaymentException('DNA refused the payment.');
        }

        $payment = array_filter([
            'auth' => $token,
            'invoiceId' => $checkout->invoiceId,
            'amount' => $amount,
            'currency' => $checkout->currency,
            'description' => $checkout->description,
            'paymentSettings' => [
                'terminalId' => $credentials->terminalId,
                'returnUrl' => $checkout->returnUrl,
                'failureReturnUrl' => $checkout->failureReturnUrl,
                'callbackUrl' => $checkout->callbackUrl,
                'failureCallbackUrl' => $checkout->callbackUrl,
            ],
            'customerDetails' => $checkout->customerEmail === null ? null : ['email' => $checkout->customerEmail],
        ], fn ($value) => $value !== null);
        $settings = ['isTestMode' => $credentials->testMode, 'scopes' => ['allowHosted' => true], 'autoRedirectDelayInMs' => self::AUTO_REDIRECT_MS];

        return self::PAY[$env].'/checkout/?params='.self::encode($payment).'&data='.self::encode($settings);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data): string
    {
        return base64_encode(LzString::compressToEncodedURIComponent((string) json_encode((object) $data)));
    }
}
