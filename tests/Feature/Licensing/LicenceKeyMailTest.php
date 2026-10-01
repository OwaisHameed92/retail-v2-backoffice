<?php

use App\Domain\Licensing\LicenceKey;
use App\Domain\Mail\Data\LicenceKeyData;
use App\Domain\Mail\Data\TillKeyData;
use App\Domain\Mail\Mailables\LicenceKeyMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Mail\Support\EmailTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

test('the licence key email shows the key and how to use it', function () {
    $mail = new LicenceKeyMail(new LicenceKeyData('Khan Mini Mart', 'Aisha Khan', [new TillKeyData('Leeds', 'Till 3', 'SSP-7K2Q-9DMF-3XRA-P8T5')]));

    $mail->assertSeeInHtml('SSP-7K2Q-9DMF-3XRA-P8T5')
        ->assertSeeInHtml('Hi Aisha')
        ->assertSeeInHtml('Till 3')
        ->assertSeeInText('Leeds – Till 3: SSP-7K2Q-9DMF-3XRA-P8T5')
        ->assertDontSeeInHtml('no longer works');

    expect($mail->subjectLine())->toBe('Your licence key for Till 3')
        ->and(json_encode($mail->logMeta()))->not->toContain('P8T5');
});

test('a replaced key says the old one stopped working', function () {
    $mail = new LicenceKeyMail(new LicenceKeyData('Khan Mini Mart', 'Aisha Khan', [
        new TillKeyData('Leeds', 'Till 1', 'SSP-7K2Q-9DMF-3XRA-P8T5'),
        new TillKeyData('Leeds', 'Till 2', 'SSP-4HWC-J6ZB-81ME-QV5H'),
    ], replacesOldKey: true));

    $mail->assertSeeInHtml('The old keys no longer work');
    expect($mail->subjectLine())->toBe('Your 2 new licence keys');
});

test('it is a registered template with valid sample keys', function () {
    expect(EmailTemplates::find('licence-key'))->toBe(LicenceKeyMail::class);

    foreach ([LicenceKeyMail::sample(), WelcomeTenantMail::sample()] as $sample) {
        foreach ($sample->data->tills as $till) {
            expect(LicenceKey::isValid($till->licenceKey))->toBeTrue("{$till->licenceKey} is not a valid key");
        }
    }
});

test('the email log keeps the facts, never the key', function () {
    Mail::to('aisha@example.test')->queue(new LicenceKeyMail(new LicenceKeyData('Khan Mini Mart', 'Aisha Khan', [new TillKeyData('Leeds', 'Till 3', 'SSP-7K2Q-9DMF-3XRA-P8T5')])));

    $log = EmailLog::query()->sole();

    expect($log->template)->toBe('licence-key')
        ->and($log->meta)->toBeIgnoringKeyOrder(['business' => 'Khan Mini Mart', 'tills' => 1, 'replaced' => false])
        ->and(json_encode(DB::table('email_logs')->get()))->not->toContain('P8T5');
});
