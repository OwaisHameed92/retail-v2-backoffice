<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Enums\EventStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessEvent;
use App\Domain\Billing\GoCardless\Support\EventCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one stored webhook event (queued job; replays and the reconcile call it again safely). Every handler
 * fetches the resource from GoCardless and applies its current state, so events arriving twice, late or out of
 * order leave the same result. Not ours or not a kind we act on: ignored (logged). A failure is kept on the event
 * (status failed) and rethrown so the queue retries it.
 */
class ProcessGoCardlessEvent
{
    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly ApplyGoCardlessPayment $applyPayment,
        private readonly ApplyMandate $applyMandate,
        private readonly ApplySubscription $applySubscription,
        private readonly CompleteMandateSetup $completeSetup,
    ) {}

    public function handle(GoCardlessEvent $event, bool $force = false): EventStatus
    {
        if (! $force && in_array($event->status, [EventStatus::Processed, EventStatus::Ignored], true)) {
            return $event->status;
        }

        $event->attempts++;
        $event->save();

        try {
            [$status, $company, $note] = $this->dispatch($event);
        } catch (Throwable $exception) {
            $event->forceFill(['status' => EventStatus::Failed, 'error' => mb_substr($exception->getMessage(), 0, 1000)])->save();

            throw $exception;
        }

        if ($status === EventStatus::Ignored) {
            Log::info('GoCardless event ignored', ['event' => $event->gc_event_id, 'type' => $event->type(), 'why' => $note]);
        }

        $event->forceFill([
            'status' => $status,
            'company_id' => $company !== null ? $company->id : $event->company_id,
            'error' => $status === EventStatus::Ignored ? $note : null,
            'processed_at' => CarbonImmutable::now(),
        ])->save();

        return $status;
    }

    /**
     * @return array{0: EventStatus, 1: Company|null, 2: string|null}
     */
    private function dispatch(GoCardlessEvent $event): array
    {
        $resource = $event->resource_type;

        if ($resource === 'payments' && ($id = $event->link('payment')) !== null) {
            $payment = $this->client->payment($id);
            $company = EventCompany::find($event, $payment->metadata) ?? EventCompany::forMandate($payment->mandateId);

            if ($company === null) {
                return [EventStatus::Ignored, null, 'Not one of our payments'];
            }

            $reason = $event->details['description'] ?? null;
            $this->applyPayment->handle($company, $payment, $payment->status->isProblem() && is_string($reason) ? $reason : null);

            return [EventStatus::Processed, $company, null];
        }

        if ($resource === 'mandates' && ($id = $event->link('mandate')) !== null) {
            $mandate = $this->client->mandate($id);
            $company = EventCompany::find($event, $mandate->metadata);

            if ($company === null) {
                return [EventStatus::Ignored, null, 'Not one of our mandates'];
            }

            $this->applyMandate->handle($company, $mandate);

            return [EventStatus::Processed, $company, null];
        }

        if ($resource === 'billing_requests' && ($id = $event->link('billing_request')) !== null) {
            $company = EventCompany::find($event);

            if ($company === null) {
                return [EventStatus::Ignored, null, 'Not one of our billing requests'];
            }

            if ($event->action === 'fulfilled') {
                $this->completeSetup->handle($company, $id);
            }

            return [EventStatus::Processed, $company, null];
        }

        if ($resource === 'subscriptions' && ($id = $event->link('subscription')) !== null) {
            $company = EventCompany::find($event);

            if ($company === null) {
                return [EventStatus::Ignored, null, 'Not one of our subscriptions'];
            }

            $this->applySubscription->handle($company, $this->client->subscription($id));

            return [EventStatus::Processed, $company, null];
        }

        return [EventStatus::Ignored, EventCompany::find($event), 'Event type not handled'];
    }
}
