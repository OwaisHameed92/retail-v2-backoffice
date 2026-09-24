<?php

use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadNote;
use App\Domain\Mail\Data\NewLeadData;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use App\Domain\Mail\Mailables\LeadRejectedMail;
use App\Domain\Mail\Support\EmailTemplates;
use Database\Seeders\LeadSeeder;
use Illuminate\Support\Facades\Mail;

test('the local seeder adds realistic leads in every working status, once, without emailing anyone', function () {
    Mail::fake();

    $this->seed(LeadSeeder::class);
    $this->seed(LeadSeeder::class);

    $statuses = Lead::query()->pluck('status')->map(fn (LeadStatus $s) => $s->value)->unique()->sort()->values()->all();

    expect(Lead::query()->count())->toBe(6)
        ->and($statuses)->toBe(['contacted', 'new', 'rejected'])
        ->and(Lead::query()->whereNotNull('follow_up_at')->count())->toBeGreaterThan(0)
        ->and(LeadNote::query()->count())->toBeGreaterThanOrEqual(6);
    Mail::assertNothingQueued();
});

test('the declined-trial email is registered, branded and polite', function () {
    config(['sspos.support_email' => 'help@switchandsave.test']);

    expect(EmailTemplates::find('lead-rejected'))->toBe(LeadRejectedMail::class)
        ->and(LeadRejectedMail::audience())->toBe('customer');

    $mail = LeadRejectedMail::sample();
    $mail->assertHasSubject('Your Switch & Save trial request')
        ->assertHasReplyTo('help@switchandsave.test')
        ->assertSeeInHtml('Hi Imran')
        ->assertSeeInHtml('Patel News &amp; Booze', false)
        ->assertSeeInText('not able to offer a free trial');

    expect($mail->logMeta())->toBe(['business' => 'Patel News & Booze']);
});

test('the staff alert shows who added the lead and a possible duplicate', function () {
    $mail = new AdminNewLeadMail(new NewLeadData(
        contactName: 'Imran Patel',
        businessName: 'Patel News',
        email: 'imran@patel.test',
        phone: null,
        shops: 1,
        tills: 2,
        receivedAt: now(),
        leadId: '01k5example',
        possibleDuplicate: 'Same email as tenant Khan Mini Mart',
        addedBy: 'Sam Sales',
    ));

    $mail->assertSeeInHtml('Sam Sales')
        ->assertSeeInHtml('Same email as tenant Khan Mini Mart')
        ->assertSeeInHtml('/admin/leads/01k5example');
});
