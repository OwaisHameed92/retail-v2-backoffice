<?php

namespace Tests\Feature\Billing\GoCardless;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Actions\SendMandateSetupEmail;
use App\Domain\Billing\GoCardless\Actions\StartMandateSetup;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Support\FakeGoCardlessClient;
use App\Domain\Billing\GoCardless\Support\WebhookSignature;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for the module 1.12 tests (with TenantTestHelpers, LicensingTestHelpers, BillingTestHelpers). GoCardless
 * is FakeGoCardlessClient: no network.
 */
trait GoCardlessTestHelpers
{
    public FakeGoCardlessClient $gc;

    private int $eventSequence = 0;

    public function fakeGoCardless(): FakeGoCardlessClient
    {
        config(['services.gocardless.webhook_secret' => 'whsec_test', 'services.gocardless.access_token' => '']);

        return $this->gc = FakeGoCardlessClient::install();
    }

    /** A paying tenant (tills paid to 31 Oct, £25 a month per till) switched to Direct Debit. */
    public function directDebitTenant(string $name = 'Khan Mini Mart', int $tills = 2, string $code = 'LDS', ?string $setupFee = null, int $instalments = 1, SetupFeeMethod $method = SetupFeeMethod::DirectDebit): Company
    {
        $company = $this->payingTenant($name, $tills, $code);
        $account = $this->billingAccountOf($company);
        $account->billing_mode = BillingMode::DirectDebit;
        $account->setup_fee_override = $setupFee;
        $account->setup_fee_instalments = $instalments;
        $account->setup_fee_method = $method;
        $account->save();

        return $company->refresh();
    }

    /** The owner completes the GoCardless page and GoCardless tells us (billing_requests.fulfilled). */
    public function setUpMandate(Company $company): string
    {
        app(StartMandateSetup::class)->handle($company);
        $requestId = (string) $this->billingAccountOf($company)->gc_billing_request_id;
        $mandate = $this->gc->fulfil($requestId);

        $this->webhook([$this->gcEvent('billing_requests', 'fulfilled', ['billing_request' => $requestId, 'mandate_request_mandate' => $mandate->id, 'customer' => (string) $mandate->customerId])])->assertOk();

        return $mandate->id;
    }

    public function sendSetupEmail(Company $company): int
    {
        return app(SendMandateSetupEmail::class)->handle($company);
    }

    /**
     * @param  array<string, string>  $links
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function gcEvent(string $resource, string $action, array $links, array $details = []): array
    {
        return [
            'id' => 'EV'.str_pad((string) ++$this->eventSequence, 8, '0', STR_PAD_LEFT),
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'resource_type' => $resource,
            'action' => $action,
            'links' => $links,
            'details' => $details + ['origin' => 'gocardless', 'cause' => $action],
        ];
    }

    /** A payment's status changes at GoCardless and the webhook arrives. */
    public function paymentEvent(GcPayment $payment, PaymentStatus $status, string $action, array $details = []): TestResponse
    {
        $this->gc->setPaymentStatus($payment->id, $status);

        return $this->webhook([$this->gcEvent('payments', $action, array_filter(['payment' => $payment->id, 'subscription' => $payment->subscriptionId, 'mandate' => $payment->mandateId]), $details)]);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function webhook(array $events, ?string $signature = null): TestResponse
    {
        $body = (string) json_encode(['events' => $events]);

        return $this->call('POST', '/webhooks/gocardless', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_SIGNATURE' => $signature ?? WebhookSignature::sign($body, 'whsec_test'),
        ], $body);
    }
}
