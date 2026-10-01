<?php

use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Mail\Mailables\OwnerAlertMail;
use App\Domain\Mail\Mailables\OwnerAlertResolvedMail;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Notifications\Models\AlertDispatch;
use App\Domain\Notifications\Models\AlertNotification;
use App\Domain\Notifications\Models\AlertPreference;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Notifications\AlertTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Feature\TillHealth\TillHealthHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, TillHealthHelpers::class, AlertTestHelpers::class);

/* Module 7.8: urgent owner alerts (till offline, sync failing) straight away, de-duplicated, with "resolved" emails. */

beforeEach(function () {
    Mail::fake();
    // Wednesday 7 Oct 2026, 12:00 London: the shops are open (default trading hours 08:00-20:00).
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', 'Europe/London'));
    $this->company = $this->alertTenant();
    $this->owner = $this->ownerOf($this->company);
    $this->leeds = $this->branchOf($this->company, 'LDS');
    $this->bradford = $this->branchOf($this->company, 'BRD');
});

test('a till offline during opening hours is emailed straight away to owners and managers, never to staff', function () {
    $manager = $this->member($this->company, CompanyRole::Manager);
    $staff = $this->member($this->company, CompanyRole::Staff);
    $accountant = $this->member($this->company, CompanyRole::Accountant);
    $this->raiseAlert($this->till($this->company, 'LDS', '02'));

    $totals = $this->checkAlerts();

    expect($totals)->toMatchArray(['emailed' => 2, 'notified' => 2]);
    Mail::assertQueued(OwnerAlertMail::class, 2);
    Mail::assertQueued(OwnerAlertMail::class, fn (OwnerAlertMail $mail) => $mail->hasTo($this->owner->email)
        && $mail->subjectLine() === 'Till 2 at Leeds is offline'
        && str_contains($mail->data->unsubscribeUrl, 'signature=')
        && $mail->data->url === config('sspos.portal_url').'/app/shops/'.$this->leeds->id);
    Mail::assertQueued(OwnerAlertMail::class, fn (OwnerAlertMail $mail) => $mail->hasTo($manager->email));
    Mail::assertNotQueued(OwnerAlertMail::class, fn (OwnerAlertMail $mail) => $mail->hasTo($staff->email) || $mail->hasTo($accountant->email));

    $bell = AlertNotification::withoutCompanyScope()->where('user_id', $this->owner->id)->sole();
    expect($bell->title)->toBe('Till 2 at Leeds is offline')->and($bell->tone)->toBe('danger')
        ->and($bell->url)->toBe('/app/shops/'.$this->leeds->id);
});

test('the whole flow: a silent till is alerted only in trading hours, emailed once, and resolved when it is back', function () {
    config(['till-health.alert_offline_hours' => 4]);
    $licence = $this->till($this->company, 'LDS');
    // Silent since Sunday evening; Wednesday 23:00 is not trading time: no alert, no email.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 23:00', 'Europe/London'));
    $this->seen($licence, CarbonImmutable::parse('2026-10-04 19:00', 'Europe/London'));
    $this->refreshHealth();
    $this->checkAlerts();
    Mail::assertNotQueued(OwnerAlertMail::class);

    // Thursday 09:00: trading, offline long enough.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00', 'Europe/London'));
    $this->refreshHealth();
    $this->checkAlerts();
    Mail::assertQueued(OwnerAlertMail::class, 1);

    // Still offline five minutes later: no repeat.
    $this->travelTo(now()->addMinutes(5));
    $this->refreshHealth();
    $this->checkAlerts();
    Mail::assertQueued(OwnerAlertMail::class, 1);

    // The till validates again: one "resolved" email.
    $this->seen($licence, now()->toImmutable());
    $this->refreshHealth();
    expect($this->checkAlerts()['resolved'])->toBe(1);
    Mail::assertQueued(OwnerAlertResolvedMail::class, fn (OwnerAlertResolvedMail $mail) => $mail->hasTo($this->owner->email)
        && $mail->subjectLine() === 'Resolved: Till 1 at Leeds is back online');
});

test('a problem that clears and comes back within 6 hours is not emailed again; after 6 hours it is', function () {
    $licence = $this->till($this->company, 'LDS');
    $alert = $this->raiseAlert($licence);
    $this->checkAlerts();
    $this->clearAlert($alert);
    $this->checkAlerts();
    Mail::assertQueued(OwnerAlertMail::class, 1);
    Mail::assertQueued(OwnerAlertResolvedMail::class, 1);

    // Back two hours later: held back (muted), and its later clearing is silent too.
    $this->travelTo(now()->addHours(2));
    $again = $this->raiseAlert($licence);
    expect($this->checkAlerts())->toMatchArray(['emailed' => 0, 'muted' => 1]);
    $this->clearAlert($again);
    $this->checkAlerts();
    Mail::assertQueued(OwnerAlertMail::class, 1);
    Mail::assertQueued(OwnerAlertResolvedMail::class, 1);

    // Back seven hours after the first email: emailed again.
    $this->travelTo(now()->addHours(5));
    $this->raiseAlert($licence);
    expect($this->checkAlerts()['emailed'])->toBe(1);
    Mail::assertQueued(OwnerAlertMail::class, 2);
});

test('sync failing and stalled are one alert type, worded for each problem', function () {
    $this->raiseAlert($this->till($this->company, 'LDS'), LicenceAlertType::SyncFailing, 'pushRejected: unknown product');
    $this->raiseAlert($this->till($this->company, 'BRD'), LicenceAlertType::SyncStalled, 'Last sync 7 Oct 2026, 06:00.');

    $this->checkAlerts();

    Mail::assertQueued(OwnerAlertMail::class, fn (OwnerAlertMail $mail) => $mail->subjectLine() === 'Sync is failing at Leeds');
    Mail::assertQueued(OwnerAlertMail::class, fn (OwnerAlertMail $mail) => $mail->subjectLine() === 'Sync has stalled at Bradford');
});

test('a one-shop manager hears only about their shop; a multi-shop owner only about the shops they picked', function () {
    $leedsManager = $this->member($this->company, CompanyRole::Manager, $this->leeds->id);
    AlertPreference::withoutCompanyScope()->create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'deliveries' => [], 'branch_ids' => [$this->leeds->id]]);
    $this->raiseAlert($this->till($this->company, 'BRD'));

    $this->checkAlerts();

    Mail::assertNotQueued(OwnerAlertMail::class);
    expect(AlertNotification::withoutCompanyScope()->count())->toBe(0);

    $this->raiseAlert($this->till($this->company, 'LDS'));
    $this->checkAlerts();
    Mail::assertQueued(OwnerAlertMail::class, fn (OwnerAlertMail $mail) => $mail->hasTo($leedsManager->email));
    Mail::assertQueued(OwnerAlertMail::class, fn (OwnerAlertMail $mail) => $mail->hasTo($this->owner->email));
});

test('digest or off choices: digest users get a bell entry but no email, off users nothing', function () {
    $digest = $this->member($this->company, CompanyRole::Manager);
    $off = $this->member($this->company, CompanyRole::Manager);
    AlertPreference::withoutCompanyScope()->create(['company_id' => $this->company->id, 'user_id' => $digest->id, 'deliveries' => ['tillOffline' => 'digest']]);
    AlertPreference::withoutCompanyScope()->create(['company_id' => $this->company->id, 'user_id' => $off->id, 'deliveries' => ['tillOffline' => 'off']]);
    $this->raiseAlert($this->till($this->company, 'LDS'));

    $this->checkAlerts();

    Mail::assertNotQueued(OwnerAlertMail::class, fn (OwnerAlertMail $mail) => $mail->hasTo($digest->email) || $mail->hasTo($off->email));
    expect(AlertNotification::withoutCompanyScope()->where('user_id', $digest->id)->count())->toBe(1)
        ->and(AlertNotification::withoutCompanyScope()->where('user_id', $off->id)->count())->toBe(0);
});

test('alerts of one business never reach another business\'s members', function () {
    $other = $this->alertTenant('Patel News');
    $otherOwner = $this->ownerOf($other);
    $this->raiseAlert($this->till($this->company, 'LDS'));

    $this->checkAlerts();

    Mail::assertNotQueued(OwnerAlertMail::class, fn (OwnerAlertMail $mail) => $mail->hasTo($otherOwner->email));
    expect(AlertNotification::withoutCompanyScope()->where('company_id', $other->id)->count())->toBe(0)
        ->and(AlertDispatch::withoutCompanyScope()->where('company_id', $other->id)->count())->toBe(0);
});

test('an alert cleared because the business was suspended sends no "resolved" email', function () {
    $alert = $this->raiseAlert($this->till($this->company, 'LDS'));
    $this->checkAlerts();
    $this->company->forceFill(['status' => CompanyStatus::Suspended])->save();
    $this->clearAlert($alert);

    expect($this->checkAlerts()['resolved'])->toBe(0);
    Mail::assertNotQueued(OwnerAlertResolvedMail::class);
    expect(AlertDispatch::withoutCompanyScope()->sole()->state)->toBe('resolved');
});

test('alert emails are queued, encrypted and written to the email log with the business', function () {
    $this->app->forgetInstance('mail.manager');
    Mail::clearResolvedInstance('mail.manager');
    Queue::fake();
    $this->raiseAlert($this->till($this->company, 'LDS', '02'));

    $this->checkAlerts();

    Queue::assertPushed(SendQueuedMailable::class, fn (SendQueuedMailable $job) => $job->shouldBeEncrypted);
    $log = EmailLog::query()->where('template', 'owner-alert')->sole();
    expect($log->status->value)->toBe('queued')
        ->and($log->to)->toBe($this->owner->email)
        ->and($log->company_id)->toBe($this->company->id)
        ->and($log->meta)->toBeIgnoringKeyOrder(['business' => 'Khan Mini Mart', 'alert' => 'tillOffline', 'shop' => 'Leeds']);
});
