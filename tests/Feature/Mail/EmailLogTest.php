<?php

use App\Domain\Mail\Data\TillKeyData;
use App\Domain\Mail\Data\WelcomeTenantData;
use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use App\Domain\Mail\Mailables\TrialEndedMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Mail\Support\EmailLogRecorder;
use App\Domain\Tenancy\Models\Company;
use App\Listeners\RecordEmailLog;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

const SECRET_KEYS = ['SSP-SECR-ETKE-Y111-AAAA', 'SSP-SECR-ETKE-Y222-BBBB'];

function welcomeWithSecretKeys(?string $companyId = null): WelcomeTenantMail
{
    return new WelcomeTenantMail(new WelcomeTenantData(
        businessName: 'Khan Mini Mart',
        ownerName: 'Aisha Khan',
        ownerEmail: 'aisha@example.test',
        loginUrl: 'https://portal.example.test/login',
        tills: [
            new TillKeyData('High Street', 'Till 1', SECRET_KEYS[0]),
            new TillKeyData('High Street', 'Till 2', SECRET_KEYS[1]),
        ],
        trialDays: 7,
        companyId: $companyId,
    ));
}

/** Every column of every email_logs row as one string. */
function allEmailLogText(): string
{
    return json_encode(DB::table('email_logs')->get()->all(), JSON_THROW_ON_ERROR);
}

it('listens to the mailer events', function () {
    Event::fake();

    Event::assertListening(MessageSending::class, [RecordEmailLog::class, 'handleSending']);
    Event::assertListening(MessageSent::class, [RecordEmailLog::class, 'handleSent']);
});

it('logs a branded email as queued, then sent with its message id', function () {
    $company = Company::factory()->create();
    Queue::fake();

    Mail::to('aisha@example.test')->queue(welcomeWithSecretKeys($company->id));

    $log = EmailLog::query()->sole();
    expect($log->status)->toBe(EmailStatus::Queued)
        ->and($log->to)->toBe('aisha@example.test')
        ->and($log->template)->toBe('welcome-tenant')
        ->and($log->mailable)->toBe(WelcomeTenantMail::class)
        ->and($log->subject)->toBe('Welcome to Switch & Save – your licence keys')
        ->and($log->company_id)->toBe($company->id)
        ->and($log->sent_at)->toBeNull()
        ->and($log->meta)->toBeIgnoringKeyOrder(['business' => 'Khan Mini Mart', 'tills' => 2, 'branches' => 1, 'trial_days' => 7]);

    // Run the queued job for real (array mail transport).
    Queue::assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) {
        expect($job->shouldBeEncrypted)->toBeTrue();
        $job->handle(app('mail.manager'));

        return true;
    });

    $log->refresh();
    expect($log->status)->toBe(EmailStatus::Sent)
        ->and($log->sent_at)->not->toBeNull()
        ->and($log->message_id)->not->toBeEmpty()
        ->and($log->error)->toBeNull()
        ->and(EmailLog::query()->count())->toBe(1);
});

it('puts licence keys in the email but never in the email log', function () {
    Mail::to('aisha@example.test')->queue(welcomeWithSecretKeys()); // sync queue in tests: sent straight away

    $sent = app('mailer')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1);

    $body = $sent->first()->getOriginalMessage()->getHtmlBody();
    expect($body)->toContain(SECRET_KEYS[0])->toContain(SECRET_KEYS[1]);

    expect(EmailLog::query()->sole()->status)->toBe(EmailStatus::Sent);

    $logText = allEmailLogText();
    foreach (SECRET_KEYS as $key) {
        expect($logText)->not->toContain($key);
    }
});

it('tags the outgoing message with its log id', function () {
    Mail::to('aisha@example.test')->queue(TrialEndedMail::sample());

    $message = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($message->getHeaders()->get(EmailLogRecorder::HEADER)?->getBodyAsString())->toBe(EmailLog::query()->sole()->id);
});

it('marks the log failed when sending throws and when the job finally fails', function () {
    Queue::fake();
    Mail::to('aisha@example.test')->queue(TrialEndedMail::sample());
    $log = EmailLog::query()->sole();

    Queue::assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) {
        $job->failed(new RuntimeException('Connection to smtp.example.test timed out'));

        return true;
    });

    $log->refresh();
    expect($log->status)->toBe(EmailStatus::Failed)
        ->and($log->error)->toBe('RuntimeException: Connection to smtp.example.test timed out')
        ->and($log->sent_at)->toBeNull();
});

it('marks the log failed when a direct send throws, and keeps the exception', function () {
    Event::listen(MessageSending::class, function () {
        throw new RuntimeException('Mail server said no');
    });

    $mail = TrialEndedMail::sample()->to('aisha@example.test');

    expect(fn () => $mail->send(app('mail.manager')))->toThrow(RuntimeException::class, 'Mail server said no');

    $log = EmailLog::query()->sole();
    expect($log->status)->toBe(EmailStatus::Failed)->and($log->error)->toContain('Mail server said no');
});

it('does not downgrade a sent email when a late failure arrives', function () {
    Mail::to('aisha@example.test')->queue(TrialEndedMail::sample());
    $log = EmailLog::query()->sole();

    app(EmailLogRecorder::class)->failed($log->id, new RuntimeException('late'));

    expect($log->refresh()->status)->toBe(EmailStatus::Sent);
});

it('sends staff alerts to the staff inbox and logs them without contact details', function () {
    config(['sspos.staff_email' => 'team@switchandsave.test']);

    Mail::queue(AdminNewLeadMail::sample());

    $log = EmailLog::query()->sole();
    expect($log->to)->toBe('team@switchandsave.test')
        ->and($log->status)->toBe(EmailStatus::Sent)
        ->and(allEmailLogText())->not->toContain('imran@patelnews.co.uk')->not->toContain('07700 900123');
});

it('logs framework mail that does not use a branded mailable', function () {
    $user = User::factory()->unverified()->create(['email' => 'new@example.test']);

    $user->notify(new VerifyEmail);

    $log = EmailLog::query()->sole();
    expect($log->template)->toBe('verify-email')
        ->and($log->mailable)->toBe(VerifyEmail::class)
        ->and($log->to)->toBe('new@example.test')
        ->and($log->status)->toBe(EmailStatus::Sent)
        ->and($log->meta)->toBe(['source' => 'notification']);
});

it('redacts secrets and drops non-scalar values from log meta', function () {
    $log = app(EmailLogRecorder::class)->queued(['a@example.test'], 'X', 'x', 'Subject', null, [
        'licence_key' => 'SSP-SHOULD-NOT-STORE',
        'reset_token' => 'abc',
        'nested' => ['licenceKey' => 'SSP-NESTED'],
        'business' => 'Khan Mini Mart',
    ]);

    expect($log->meta)->toBe(['licence_key' => '[redacted]', 'reset_token' => '[redacted]', 'business' => 'Khan Mini Mart'])
        ->and(allEmailLogText())->not->toContain('SSP-SHOULD-NOT-STORE')->not->toContain('SSP-NESTED');
});

it('prunes log rows older than twelve months', function () {
    $old = EmailLog::query()->create(['to' => 'a@example.test', 'mailable' => 'X', 'template' => 'x', 'status' => EmailStatus::Sent]);
    $old->forceFill(['created_at' => now()->subMonths(12)->subDay()])->save();
    $recent = EmailLog::query()->create(['to' => 'b@example.test', 'mailable' => 'X', 'template' => 'x', 'status' => EmailStatus::Sent]);
    $recent->forceFill(['created_at' => now()->subMonths(11)])->save();

    $this->artisan('model:prune', ['--model' => [EmailLog::class]])->assertSuccessful();

    expect(EmailLog::query()->pluck('id')->all())->toBe([$recent->id]);
});
