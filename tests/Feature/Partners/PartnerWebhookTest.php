<?php

use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Partners\Actions\DeliverPartnerWebhook;
use App\Domain\Partners\Actions\QueuePartnerWebhook;
use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Enums\PartnerWebhookType;
use App\Domain\Partners\Jobs\SendPartnerWebhookJob;
use App\Domain\Partners\Models\PartnerWebhookEvent;
use App\Domain\Partners\Support\WebhookSignature;
use App\Domain\Tenancy\Actions\CancelCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Partners\PartnerTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Partner webhooks (docs/partner-api.md "Webhooks"): the outbox, the signature, retries for 24 h, one event per request
 * and type, and the listeners that follow a partner-made business (setup fee paid, first till, cancelled).
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, LicenceApiHelpers::class, PartnerTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));
    $this->setVat(true);
    $this->standardPlan();
    $this->setupOnlyPlan();
});

/** A partner deal approved into a business; returns [company, partner key, webhook secret]. */
function approvedDeal(object $test, array $overrides = []): array
{
    [, $key, $secret] = $test->partnerWithKey();
    $test->putDeal($key, $test->dealBody($overrides))->assertCreated();
    $test->approveAs($test->requestFor())->assertSessionHasNoErrors();

    return [Company::query()->sole(), $key, $secret];
}

test('a webhook is POSTed with the exact body and a valid X-Partner-Signature', function () {
    Http::fake(['zivaro.test/*' => Http::response('', 204)]);
    [$company, , $secret] = approvedDeal($this);

    Http::assertSent(function (HttpRequest $request) use ($secret, $company) {
        $body = $request->body();
        $event = json_decode($body, true);

        return $request->url() === self::WEBHOOK_URL
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/json')
            && WebhookSignature::verify($secret, $body, $request->header(WebhookSignature::HEADER)[0], now()->getTimestamp())
            && preg_match('/^t=\d+,v1=[0-9a-f]{64}$/', $request->header(WebhookSignature::HEADER)[0]) === 1
            && str_starts_with($event['id'], 'evt_')
            && $event['type'] === 'tenant.approved'
            && $event['createdAt'] === '2026-10-12T09:00:00.000Z'
            && $event['data']['tenantId'] === $company->id
            && $event['data']['status'] === 'approved'
            && $request->header('X-Partner-Event-Id')[0] === $event['id'];
    });

    $event = PartnerWebhookEvent::query()->sole();
    expect($event->delivered_at)->not->toBeNull()->and($event->attempts)->toBe(1)->and($event->last_status)->toBe(204);
});

test('every event carries a newer data.updatedAt than the one before', function () {
    Http::fake(['zivaro.test/*' => Http::response('', 200)]);
    [$company, $key] = approvedDeal($this, ['setupFeePerTill' => '0']);
    app(CancelCompany::class)->handle($company, 'Closed');

    $stamps = PartnerWebhookEvent::query()->get()
        ->mapWithKeys(fn (PartnerWebhookEvent $event) => [$event->type->value => json_decode($event->payload, true)['data']['updatedAt']])->all();

    expect($stamps)->toBe([
        'tenant.approved' => '2026-10-12T09:00:00.002Z',
        'tenant.setup_fee_paid' => '2026-10-12T09:00:00.003Z',
        'tenant.cancelled' => '2026-10-12T09:00:00.005Z',
    ]);
    // Even within one millisecond every change moves it on (the cancellation itself, then its event).
    $this->getDeal($key)->assertJsonPath('updatedAt', '2026-10-12T09:00:00.005Z')->assertJsonPath('status', 'cancelled');
});

test('the signature refuses another secret, a changed body or one older than 5 minutes', function () {
    $header = WebhookSignature::header('whsec_one', '{"a":1}', 1_700_000_000);

    expect(WebhookSignature::verify('whsec_one', '{"a":1}', $header, 1_700_000_100))->toBeTrue()
        ->and(WebhookSignature::verify('whsec_two', '{"a":1}', $header, 1_700_000_100))->toBeFalse()
        ->and(WebhookSignature::verify('whsec_one', '{"a":2}', $header, 1_700_000_100))->toBeFalse()
        ->and(WebhookSignature::verify('whsec_one', '{"a":1}', $header, 1_700_000_301))->toBeFalse()
        ->and($header)->toBe('t=1700000000,v1='.hash_hmac('sha256', '1700000000.{"a":1}', 'whsec_one'));
});

test('a failed delivery is retried with exponential backoff, the same id and body, then delivered', function () {
    Http::fakeSequence('zivaro.test/*')->push('down', 500)->push('down', 503)->push('', 200);
    approvedDeal($this);
    $event = PartnerWebhookEvent::query()->sole();

    expect($event->attempts)->toBe(1)->and($event->last_status)->toBe(500)->and($event->last_error)->toBe('HTTP 500')
        ->and($event->next_attempt_at->toIso8601String())->toBe('2026-10-12T09:01:00+00:00');

    // Not due yet: nothing is sent.
    $this->artisan('partners:send-webhooks')->expectsOutputToContain('Queued 0 webhooks.')->assertSuccessful();

    $this->travel(61)->seconds();
    $this->artisan('partners:send-webhooks')->assertSuccessful();
    expect($event->refresh()->attempts)->toBe(2)->and($event->next_attempt_at->toIso8601String())->toBe('2026-10-12T09:03:01+00:00');

    $this->travel(2)->minutes();
    $this->artisan('partners:send-webhooks')->assertSuccessful();
    expect($event->refresh()->delivered_at)->not->toBeNull()->and($event->attempts)->toBe(3);

    $bodies = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->body())->unique();
    expect($bodies)->toHaveCount(1)->and(json_decode($bodies->first(), true)['id'])->toBe($event->id);
});

test('backoff doubles up to an hour; after 24 hours the event is given up', function () {
    expect(array_map(fn (int $n) => DeliverPartnerWebhook::backoffSeconds($n), [1, 2, 3, 4, 6, 7, 10]))
        ->toBe([60, 120, 240, 480, 1920, 3600, 3600]);

    Http::fake(['zivaro.test/*' => Http::response('nope', 500)]);
    approvedDeal($this);
    $event = PartnerWebhookEvent::query()->sole();

    for ($i = 0; $i < 40 && $event->refresh()->isPending(); $i++) {
        $this->travelTo($event->next_attempt_at->addSecond());
        app(DeliverPartnerWebhook::class)->handle($event->id);
    }

    $event->refresh();
    expect($event->failed_at)->not->toBeNull()
        ->and($event->delivered_at)->toBeNull()
        ->and($event->failed_at->lessThanOrEqualTo($event->created_at->addHours(24)))->toBeTrue()
        ->and($event->attempts)->toBeGreaterThan(20);

    // Given up: never sent again.
    $sent = count(Http::recorded());
    $this->travel(1)->hours();
    expect(app(DeliverPartnerWebhook::class)->handle($event->id))->toBe('skipped')->and(count(Http::recorded()))->toBe($sent);
});

test('a partner without a webhook URL keeps the event waiting for it', function () {
    Http::fake(['zivaro.test/*' => Http::response('', 200)]);
    [, $key] = $this->partnerWithKey(webhookUrl: null);
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->approveAs($this->requestFor())->assertSessionHasNoErrors();

    $event = PartnerWebhookEvent::query()->sole();
    expect($event->delivered_at)->toBeNull()->and($event->last_error)->toContain('no webhook URL');
    Http::assertNothingSent();

    $event->partner->forceFill(['webhook_url' => self::WEBHOOK_URL])->save();
    $this->travel(2)->minutes();
    $this->artisan('partners:send-webhooks', ['--now' => true])->expectsOutputToContain('1 delivered')->assertSuccessful();
    expect($event->refresh()->delivered_at)->not->toBeNull();
});

test('an event is made once per request and type, and a worker never sends one twice at once', function () {
    Queue::fake();
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $request = $this->requestFor();

    $first = app(QueuePartnerWebhook::class)->handle($request, PartnerWebhookType::Rejected);
    $again = app(QueuePartnerWebhook::class)->handle($request, PartnerWebhookType::Rejected);

    expect($again->id)->toBe($first->id)->and(PartnerWebhookEvent::query()->count())->toBe(1);
    Queue::assertPushed(SendPartnerWebhookJob::class, 1);
    expect((new SendPartnerWebhookJob($first->id))->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));

    // Claimed by one worker (locked): another attempt skips it.
    $first->forceFill(['locked_until' => now()->addMinute()])->save();
    expect(app(DeliverPartnerWebhook::class)->handle($first->id))->toBe('skipped');
});

test('tenant.setup_fee_paid goes out once the setup fee is paid in full', function () {
    Http::fake(['zivaro.test/*' => Http::response('', 200)]);
    [$company, $key] = approvedDeal($this);

    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Card, 'Card at shop'));

    $types = PartnerWebhookEvent::query()->orderBy('created_at')->pluck('type')->map->value->all();
    expect($types)->toBe(['tenant.approved', 'tenant.setup_fee_paid']);

    $paid = Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)->sole();
    $data = json_decode(PartnerWebhookEvent::query()->where('type', 'tenant.setup_fee_paid')->sole()->payload, true)['data'];
    expect($data['setupFee'])->toBe(['total' => '1800.00', 'paid' => true, 'paidAt' => '2026-10-12T09:00:00Z'])
        ->and($paid->total)->toBe('1800.00');
    $this->getDeal($key)->assertJsonPath('setupFee.paid', true);
});

test('instalments: tenant.setup_fee_paid only after the last one', function () {
    Http::fake(['zivaro.test/*' => Http::response('', 200)]);
    [$company] = approvedDeal($this);
    $account = $this->billingAccountOf($company);
    $account->setup_fee_instalments = 2;
    $account->save();

    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Cash));
    expect(PartnerWebhookEvent::query()->where('type', 'tenant.setup_fee_paid')->count())->toBe(0);

    $second = Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)->open()->sole();
    $this->pay($company, $second->balance, [$second->id => $second->balance]);
    expect(PartnerWebhookEvent::query()->where('type', 'tenant.setup_fee_paid')->count())->toBe(1);
});

test('tenant.first_till_activated goes out on the business\'s first activation only', function () {
    Http::fake(['zivaro.test/*' => Http::response('', 200)]);
    $this->withSigningKey();
    [$company, $key] = approvedDeal($this);
    $licences = Licence::withoutCompanyScope()->where('company_id', $company->id)->orderBy('register_id')->get();
    $this->giveKey($licences[0]);
    $this->giveKey($licences[1], self::OTHER_KEY);

    $this->activateTill()->assertOk();
    $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->assertOk();

    expect(PartnerWebhookEvent::query()->where('type', 'tenant.first_till_activated')->count())->toBe(1);
    $data = json_decode(PartnerWebhookEvent::query()->where('type', 'tenant.first_till_activated')->sole()->payload, true)['data'];
    expect($data['tillsActivated'])->toBe(1)->and($data['firstActivatedAt'])->toBe('2026-10-12T09:00:00Z');
    $this->getDeal($key)->assertJsonPath('tillsActivated', 2);
});

test('cancelling the business on the portal cancels the request and tells the partner', function () {
    Http::fake(['zivaro.test/*' => Http::response('', 200)]);
    [$company, $key] = approvedDeal($this);

    app(CancelCompany::class)->handle($company, 'Closed the shop');

    expect($this->requestFor()->status)->toBe(PartnerRequestStatus::Cancelled)
        ->and(PartnerWebhookEvent::query()->where('type', 'tenant.cancelled')->count())->toBe(1);
    $this->getDeal($key)->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelledReason', 'The business was cancelled on the retail portal.');
});

test('businesses not made from a partner request never produce webhooks', function () {
    Http::fake();
    $this->withSigningKey();
    [$company, $licence] = $this->keyedTenant();
    $this->activateTill()->assertOk();
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment('100.00', PaymentMethod::Cash));
    app(CancelCompany::class)->handle($company, 'Test');

    expect(PartnerWebhookEvent::query()->count())->toBe(0);
    Http::assertNothingSent();
});
