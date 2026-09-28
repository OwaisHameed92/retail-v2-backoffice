<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Data\GcBillingRequest;
use App\Domain\Billing\GoCardless\Data\GcMandate;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Data\GcSetupFlow;
use App\Domain\Billing\GoCardless\Data\GcSubscription;
use App\Domain\Billing\GoCardless\Enums\MandateStatus;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Enums\SubscriptionStatus;
use App\Domain\Billing\GoCardless\GoCardlessException;
use Carbon\CarbonImmutable;
use Closure;
use GoCardlessPro\Client;
use GoCardlessPro\Core\Exception\ApiException;
use GoCardlessPro\Core\Exception\GoCardlessProException;
use GoCardlessPro\Environment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GoCardless through the official SDK (gocardless/gocardless-pro). Dormant until `services.gocardless.access_token`
 * is set. SDK errors become GoCardlessException with GoCardless' own message; the token is never logged.
 */
final class SdkGoCardlessClient implements GoCardlessClient
{
    private ?Client $client = null;

    public function enabled(): bool
    {
        return trim((string) config('services.gocardless.access_token')) !== '';
    }

    public function environment(): string
    {
        return config('services.gocardless.environment') === 'live' ? 'live' : 'sandbox';
    }

    public function startMandateSetup(string $redirectUri, string $exitUri, array $prefill, array $metadata): GcSetupFlow
    {
        return $this->call(function (Client $client) use ($redirectUri, $exitUri, $prefill, $metadata) {
            $request = $client->billingRequests()->create(['params' => [
                'mandate_request' => ['currency' => 'GBP', 'scheme' => 'bacs'],
                'metadata' => $metadata,
            ]]);

            $flow = $client->billingRequestFlows()->create(['params' => array_filter([
                'redirect_uri' => $redirectUri,
                'exit_uri' => $exitUri,
                'prefilled_customer' => $prefill === [] ? null : $prefill,
                'links' => ['billing_request' => $request->id],
            ])]);

            $expires = self::read($flow, 'expires_at');

            return new GcSetupFlow((string) $request->id, (string) $flow->authorisation_url, is_string($expires) ? CarbonImmutable::parse($expires) : null);
        });
    }

    public function billingRequest(string $id): GcBillingRequest
    {
        return $this->call(function (Client $client) use ($id) {
            $request = $client->billingRequests()->get($id);
            $links = self::read($request, 'links');

            return new GcBillingRequest(
                id: (string) $request->id,
                status: (string) $request->status,
                customerId: self::string(self::read($links, 'customer')),
                mandateId: self::string(self::read($links, 'mandate_request_mandate')),
                metadata: self::metadata($request),
            );
        });
    }

    public function mandate(string $id): GcMandate
    {
        return $this->call(fn (Client $client) => self::toMandate($client->mandates()->get($id)));
    }

    public function createPayment(string $mandateId, int $amountPence, ?string $chargeDate, string $description, array $metadata, string $idempotencyKey): GcPayment
    {
        return $this->call(fn (Client $client) => self::toPayment($client->payments()->create([
            'params' => array_filter([
                'amount' => $amountPence,
                'currency' => 'GBP',
                'charge_date' => $chargeDate,
                'description' => mb_substr($description, 0, 100),
                'retry_if_possible' => true,
                'metadata' => $metadata,
                'links' => ['mandate' => $mandateId],
            ], fn (mixed $value) => $value !== null),
            'headers' => ['Idempotency-Key' => $idempotencyKey],
        ])));
    }

    public function payment(string $id): GcPayment
    {
        return $this->call(fn (Client $client) => self::toPayment($client->payments()->get($id)));
    }

    public function paymentsForMandate(string $mandateId, CarbonImmutable $since): array
    {
        return $this->call(function (Client $client) use ($mandateId, $since) {
            $payments = [];

            foreach ($client->payments()->all(['params' => ['mandate' => $mandateId, 'created_at[gte]' => $since->utc()->format('Y-m-d\TH:i:s.v\Z'), 'limit' => 100]]) as $payment) {
                $payments[] = self::toPayment($payment);
            }

            return $payments;
        });
    }

    public function createSubscription(string $mandateId, int $amountPence, string $intervalUnit, ?string $startDate, string $name, array $metadata, string $idempotencyKey): GcSubscription
    {
        return $this->call(fn (Client $client) => self::toSubscription($client->subscriptions()->create([
            'params' => array_filter([
                'amount' => $amountPence,
                'currency' => 'GBP',
                'interval_unit' => $intervalUnit,
                'start_date' => $startDate,
                'name' => mb_substr($name, 0, 255),
                'retry_if_possible' => true,
                'metadata' => $metadata,
                'links' => ['mandate' => $mandateId],
            ], fn (mixed $value) => $value !== null),
            'headers' => ['Idempotency-Key' => $idempotencyKey],
        ])));
    }

    public function subscription(string $id): GcSubscription
    {
        return $this->call(fn (Client $client) => self::toSubscription($client->subscriptions()->get($id)));
    }

    public function updateSubscriptionAmount(string $id, int $amountPence): GcSubscription
    {
        return $this->call(fn (Client $client) => self::toSubscription($client->subscriptions()->update($id, ['params' => ['amount' => $amountPence]])));
    }

    public function pauseSubscription(string $id): GcSubscription
    {
        return $this->call(fn (Client $client) => self::toSubscription($client->subscriptions()->pause($id)));
    }

    public function resumeSubscription(string $id): GcSubscription
    {
        return $this->call(fn (Client $client) => self::toSubscription($client->subscriptions()->resume($id)));
    }

    public function cancelSubscription(string $id): GcSubscription
    {
        return $this->call(fn (Client $client) => self::toSubscription($client->subscriptions()->cancel($id)));
    }

    /**
     * @template T
     *
     * @param  Closure(Client): T  $request
     * @return T
     */
    private function call(Closure $request): mixed
    {
        if (! $this->enabled()) {
            throw GoCardlessException::notConfigured();
        }

        $this->client ??= new Client([
            'access_token' => (string) config('services.gocardless.access_token'),
            'environment' => $this->environment() === 'live' ? Environment::LIVE : Environment::SANDBOX,
        ]);

        try {
            return $request($this->client);
        } catch (ApiException $exception) {
            Log::warning('GoCardless refused a request', ['type' => $exception->getType(), 'message' => $exception->getMessage(), 'request_id' => $exception->getRequestId()]);

            throw new GoCardlessException('GoCardless: '.$exception->getMessage(), previous: $exception);
        } catch (GoCardlessProException $exception) {
            Log::warning('GoCardless could not be reached', ['message' => $exception->getMessage()]);

            throw new GoCardlessException('GoCardless could not be reached. Try again in a minute.', previous: $exception);
        }
    }

    private static function toMandate(object $mandate): GcMandate
    {
        return new GcMandate(
            id: (string) $mandate->id,
            status: MandateStatus::fromGoCardless((string) $mandate->status),
            customerId: self::string(self::read(self::read($mandate, 'links'), 'customer')),
            nextPossibleChargeDate: self::string(self::read($mandate, 'next_possible_charge_date')),
            metadata: self::metadata($mandate),
        );
    }

    private static function toPayment(object $payment): GcPayment
    {
        $links = self::read($payment, 'links');

        return new GcPayment(
            id: (string) $payment->id,
            amountPence: (int) self::read($payment, 'amount'),
            status: PaymentStatus::fromGoCardless((string) self::read($payment, 'status')),
            chargeDate: self::string(self::read($payment, 'charge_date')),
            mandateId: self::string(self::read($links, 'mandate')),
            subscriptionId: self::string(self::read($links, 'subscription')),
            description: self::string(self::read($payment, 'description')),
            metadata: self::metadata($payment),
        );
    }

    private static function toSubscription(object $subscription): GcSubscription
    {
        $upcoming = self::read($subscription, 'upcoming_payments');
        $first = is_array($upcoming) && $upcoming !== [] ? reset($upcoming) : null;

        return new GcSubscription(
            id: (string) $subscription->id,
            amountPence: (int) self::read($subscription, 'amount'),
            intervalUnit: self::read($subscription, 'interval_unit') === 'yearly' ? 'yearly' : 'monthly',
            status: SubscriptionStatus::fromGoCardless((string) self::read($subscription, 'status')),
            mandateId: self::string(self::read(self::read($subscription, 'links'), 'mandate')),
            upcomingChargeDate: self::string(self::read($first, 'charge_date')),
            metadata: self::metadata($subscription),
        );
    }

    /** A field of an SDK resource, stdClass or array (the SDK hands back all three), or null. */
    private static function read(mixed $source, string $key): mixed
    {
        try {
            return match (true) {
                is_array($source) => $source[$key] ?? null,
                is_object($source) => $source->{$key} ?? null,
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private static function metadata(object $resource): array
    {
        $metadata = self::read($resource, 'metadata');
        $metadata = is_object($metadata) ? get_object_vars($metadata) : (is_array($metadata) ? $metadata : []);

        return array_map('strval', array_filter($metadata, fn (mixed $value) => is_scalar($value)));
    }
}
