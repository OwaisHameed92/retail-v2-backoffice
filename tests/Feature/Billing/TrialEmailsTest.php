<?php

use App\Domain\Billing\Actions\SendTrialEmails;
use App\Domain\Mail\Mailables\TrialEndedMail;
use App\Domain\Mail\Mailables\TrialReminderMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\CancelCompany;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->atLondon('2026-10-20 10:00');
    $this->setVat(true);
});

test('the reminder goes once, two London calendar days before the trial ends', function () {
    $company = $this->trialTenant(tills: 2, trialEndsAt: '2026-10-26 10:00');
    $owner = $this->ownerOf($company);
    $send = fn (string $at) => $this->trialEmailsAt($at);

    expect($send('2026-10-23 23:59'))->toBe(['reminders' => 0, 'ended' => 0])
        ->and($send('2026-10-24 00:01'))->toBe(['reminders' => 1, 'ended' => 0])
        ->and($send('2026-10-24 18:00'))->toBe(['reminders' => 0, 'ended' => 0])
        ->and($send('2026-10-25 06:00'))->toBe(['reminders' => 0, 'ended' => 0]);

    Mail::assertQueued(TrialReminderMail::class, 1);
    Mail::assertQueued(TrialReminderMail::class, fn (TrialReminderMail $mail) => $mail->hasTo($owner->email)
        && $mail->data->daysLeft === 2
        && $mail->data->tillCount === 2
        && $mail->data->priceSummary === '£25.00 per till per month'
        && $mail->data->trialEndsAt->equalTo(CarbonImmutable::parse('2026-10-26 10:00', 'Europe/London')));

    $account = $this->billingAccountOf($company);
    expect($account->trial_reminder_for->equalTo(CarbonImmutable::parse('2026-10-26 10:00', 'Europe/London')))->toBeTrue()
        ->and($account->trial_reminder_sent_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'billing.trial_reminder_sent')->count())->toBe(1);
});

test('the "trial ended" email goes once after the trial ends', function () {
    $company = $this->trialTenant(trialEndsAt: '2026-10-26 10:00');
    $send = fn (string $at) => $this->trialEmailsAt($at);

    expect($send('2026-10-26 09:59'))->toBe(['reminders' => 1, 'ended' => 0])
        ->and($send('2026-10-26 10:00'))->toBe(['reminders' => 0, 'ended' => 1])
        ->and($send('2026-10-26 18:00'))->toBe(['reminders' => 0, 'ended' => 0])
        ->and($send('2026-10-28 06:00'))->toBe(['reminders' => 0, 'ended' => 0]);

    Mail::assertQueued(TrialEndedMail::class, 1);
    Mail::assertQueued(TrialEndedMail::class, fn (TrialEndedMail $mail) => $mail->hasTo($this->ownerOf($company)->email)
        && $mail->data->endedAt->equalTo(CarbonImmutable::parse('2026-10-26 10:00', 'Europe/London'))
        && $mail->data->tillCount === 1);
    Mail::assertQueued(TrialReminderMail::class, fn (TrialReminderMail $mail) => $mail->data->daysLeft === 0);

    expect($this->billingAccountOf($company)->trial_ended_for)->not->toBeNull();
});

test('a trial that ended more than three days ago gets no "trial ended" email', function (string $firstRun, int $ended) {
    $this->trialTenant(trialEndsAt: '2026-10-26 10:00');

    $this->atLondon($firstRun);
    $result = app(SendTrialEmails::class)->handle(CarbonImmutable::now());

    expect($result)->toBe(['reminders' => 0, 'ended' => $ended]);
})->with([
    'two days after' => ['2026-10-28 10:00', 1],
    'just inside three days' => ['2026-10-29 09:59', 1],
    'three days after' => ['2026-10-29 10:00', 0],
    'a week after' => ['2026-11-02 10:00', 0],
]);

test('a trial extended by staff gets a reminder for its new end, and no "ended" email for the old one', function () {
    $company = $this->trialTenant(trialEndsAt: '2026-10-26 10:00');
    $send = fn (string $at) => $this->trialEmailsAt($at);

    expect($send('2026-10-24 08:00')['reminders'])->toBe(1);

    $newEnd = CarbonImmutable::parse('2026-11-02 10:00', 'Europe/London');
    foreach ($this->licencesOf($company) as $licence) {
        $licence->forceFill(['trial_ends_at' => $newEnd])->save();
    }

    expect($send('2026-10-27 08:00'))->toBe(['reminders' => 0, 'ended' => 0])
        ->and($send('2026-10-31 08:00'))->toBe(['reminders' => 1, 'ended' => 0])
        ->and($send('2026-11-01 08:00'))->toBe(['reminders' => 0, 'ended' => 0])
        ->and($send('2026-11-02 12:00'))->toBe(['reminders' => 0, 'ended' => 1]);

    Mail::assertQueued(TrialReminderMail::class, 2);
    Mail::assertQueued(TrialEndedMail::class, 1);
    Mail::assertQueued(TrialReminderMail::class, fn (TrialReminderMail $mail) => $mail->data->trialEndsAt->equalTo($newEnd));
});

test('the company trial ends with its first trial licence', function () {
    $company = $this->trialTenant(tills: 2, trialEndsAt: '2026-10-30 10:00');
    [, $second] = $this->licencesOf($company);
    $second->forceFill(['trial_ends_at' => CarbonImmutable::parse('2026-10-26 10:00', 'Europe/London')])->save();

    $this->atLondon('2026-10-24 08:00');
    app(SendTrialEmails::class)->handle(CarbonImmutable::now());

    Mail::assertQueued(TrialReminderMail::class, fn (TrialReminderMail $mail) => $mail->data->daysLeft === 2
        && $mail->data->trialEndsAt->equalTo(CarbonImmutable::parse('2026-10-26 10:00', 'Europe/London')));
});

test('every active owner gets the trial emails', function () {
    $company = $this->trialTenant(trialEndsAt: '2026-10-26 10:00');
    $second = $this->addMember($company, CompanyRole::Owner);
    $this->addMember($company, CompanyRole::Owner, active: false);

    $this->atLondon('2026-10-24 08:00');
    app(SendTrialEmails::class)->handle(CarbonImmutable::now());

    Mail::assertQueued(TrialReminderMail::class, 2);
    Mail::assertQueued(TrialReminderMail::class, fn (TrialReminderMail $mail) => $mail->hasTo($second->email));
});

test('paying companies get no trial emails', function () {
    $company = $this->trialTenant(trialEndsAt: '2026-10-26 10:00');
    $this->atLondon('2026-10-22 10:00');
    $this->pay($company, $this->issuedFor($company)->total);

    foreach (['2026-10-24 08:00', '2026-10-26 12:00'] as $at) {
        $this->atLondon($at);
        expect(app(SendTrialEmails::class)->handle(CarbonImmutable::now()))->toBe(['reminders' => 0, 'ended' => 0]);
    }

    $this->payingTenant('Paid Shop', 1, 'PDS');
    expect(app(SendTrialEmails::class)->handle(CarbonImmutable::now()))->toBe(['reminders' => 0, 'ended' => 0]);

    Mail::assertNotQueued(TrialReminderMail::class);
    Mail::assertNotQueued(TrialEndedMail::class);
});

test('suspended and cancelled companies get no trial emails', function () {
    $suspended = $this->trialTenant('Held Shop', trialEndsAt: '2026-10-26 10:00', code: 'HLD');
    $cancelled = $this->trialTenant('Closed Shop', trialEndsAt: '2026-10-26 10:00', code: 'CLS');
    app(SuspendCompany::class)->handle($suspended, 'Fraud check');
    app(CancelCompany::class)->handle($cancelled, 'Closed the shop');

    foreach (['2026-10-24 08:00', '2026-10-26 12:00'] as $at) {
        $this->atLondon($at);
        expect(app(SendTrialEmails::class)->handle(CarbonImmutable::now()))->toBe(['reminders' => 0, 'ended' => 0]);
    }

    Mail::assertNotQueued(TrialReminderMail::class);
    Mail::assertNotQueued(TrialEndedMail::class);
});

test('tills never activated have no trial to remind about', function () {
    $company = $this->licensedTenant('Boxed Shop', 1, 'BXD');

    $this->atLondon('2026-10-24 08:00');

    expect(app(SendTrialEmails::class)->handle(CarbonImmutable::now()))->toBe(['reminders' => 0, 'ended' => 0])
        ->and($this->licencesOf($company)[0]->activated_at)->toBeNull();
});

test('billing:run sends each trial email once however often it runs', function () {
    $this->trialTenant(trialEndsAt: '2026-10-26 10:00');

    $counts = [];
    foreach (['2026-10-24', '2026-10-24', '2026-10-25', '2026-10-27', '2026-10-27', '2026-10-28'] as $day) {
        $result = $this->runBillingOn($day);
        $counts[] = [$result['trialReminders'], $result['trialEnded']];
    }

    expect($counts)->toBe([[1, 0], [0, 0], [0, 0], [0, 1], [0, 0], [0, 0]]);

    Mail::assertQueued(TrialReminderMail::class, 1);
    Mail::assertQueued(TrialEndedMail::class, 1);
});
