<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Queries\AdminDashboard;
use App\Domain\Mail\Data\PartnerRequestAlertData;
use App\Domain\Mail\Mailables\AdminPartnerRequestMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Partners\PartnerTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Owner 2026-10-12: staff hear of a partner request at once — an email to the lead alert addresses for a new request,
 * one sent again after a rejection, or an approved one the partner cancelled — and it shows in the admin dashboard's
 * "Needs attention" for admins who review partner requests.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, PartnerTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Http::preventStrayRequests();
    Http::fake(['zivaro.test/*' => Http::response(['ok' => true])]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));
    config(['sspos.staff_email' => 'team@switchandsave.test', 'sspos.lead_alert_emails' => []]);
    $this->standardPlan();
    $this->setupOnlyPlan();
});

test('a new partner request emails staff once, with a link to review it', function () {
    [, $key] = $this->partnerWithKey();

    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->putDeal($key, $this->dealBody())->assertOk();   // the same body again: nothing new

    Mail::assertQueuedCount(1);
    Mail::assertQueued(AdminPartnerRequestMail::class, function (AdminPartnerRequestMail $mail) {
        $request = $this->requestFor();

        return $mail->hasTo('team@switchandsave.test')
            && $mail->data->event === PartnerRequestAlertData::NEW
            && $mail->data->requestId === $request->id
            && $mail->data->businessName === 'Istanbul Market'
            && $mail->data->partnerName === 'Zivaro'
            && $mail->data->tills === 2
            && $mail->subjectLine() === 'New partner request: Istanbul Market'
            && str_contains($mail->render(), '/admin/partner-requests/'.$request->id);
    });
});

test('the lead alert addresses get it when they are set', function () {
    config(['sspos.lead_alert_emails' => ['owner@switchandsave.test', 'sales@switchandsave.test']]);
    [, $key] = $this->partnerWithKey();

    $this->putDeal($key, $this->dealBody())->assertCreated();

    Mail::assertQueued(AdminPartnerRequestMail::class, fn (AdminPartnerRequestMail $mail) => $mail->hasTo('owner@switchandsave.test') && $mail->hasTo('sales@switchandsave.test'));
});

test('an edit while waiting for review sends no email, a rejected one sent again does', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->putDeal($key, $this->dealBody(['notes' => 'Changed the card machine']))->assertOk();
    Mail::assertQueuedCount(1);

    $this->actingAs($this->admin(), 'admin')->post(route('admin.partner-requests.reject', $this->requestFor()), ['reason' => 'Add the VAT number'])->assertRedirect();
    $this->putDeal($key, $this->dealBody(['business.vatNumber' => 'GB123456789']))->assertOk();

    Mail::assertQueued(AdminPartnerRequestMail::class, fn (AdminPartnerRequestMail $mail) => $mail->data->event === PartnerRequestAlertData::SENT_AGAIN
        && $mail->subjectLine() === 'Partner request sent again: Istanbul Market');
});

test('a partner cancelling an approved business emails staff with the reason; a pending cancel does not', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->putDeal($key, $this->dealBody(), 'zivaro-deal-43')->assertCreated();
    $this->approveAs($this->requestFor())->assertSessionHasNoErrors();

    $this->cancelDeal($key, 'zivaro-deal-43')->assertOk();   // still pending: cancelled, nothing for staff to do
    $this->cancelDeal($key, 'zivaro-deal-42', 'Customer went with another till')->assertOk();
    $this->cancelDeal($key, 'zivaro-deal-42')->assertOk();   // a repeat changes nothing

    $cancelled = Mail::queued(AdminPartnerRequestMail::class, fn (AdminPartnerRequestMail $mail) => $mail->data->event === PartnerRequestAlertData::PARTNER_CANCELLED);
    expect($cancelled)->toHaveCount(1)
        ->and($cancelled->first()->data->reason)->toBe('Customer went with another till')
        ->and($cancelled->first()->subjectLine())->toBe('Partner cancelled an approved business: Istanbul Market');
});

test('a waiting partner request shows in Needs attention for admins who review them', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $request = $this->requestFor();

    $data = app(AdminDashboard::class)->compute(CarbonImmutable::now());
    $owner = $data->forViewer(billing: true, leads: true, partners: true)['attention'];
    $accounts = $data->forViewer(billing: true, leads: false, partners: false)['attention'];

    expect(collect($owner['items'])->firstWhere('id', 'partner-request-'.$request->id))
        ->toMatchArray(['label' => 'Partner', 'text' => 'Waiting for review · Istanbul Market · Zivaro', 'href' => '/admin/partner-requests/'.$request->id])
        ->and(collect($accounts['items'])->pluck('id'))->not->toContain('partner-request-'.$request->id)
        ->and($owner['total'] - $accounts['total'])->toBe(1);

    // The owner's real dashboard (tenants.manage) has it; Accounts (no tenants.manage) does not.
    expect(collect(app(AdminDashboard::class)->forAdmin($this->admin())['attention']['items'])->pluck('id'))->toContain('partner-request-'.$request->id);
    expect(collect(app(AdminDashboard::class)->forAdmin($this->admin(AdminRole::Accounts))['attention']['items'])->pluck('id'))
        ->not->toContain('partner-request-'.$request->id);
});

test('the staff email renders on its own (template preview)', function () {
    $mail = AdminPartnerRequestMail::sample();

    expect($mail->render())->toContain('New partner request')->toContain('Istanbul Market')->toContain('Review the request');
});
