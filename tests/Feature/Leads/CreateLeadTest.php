<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Leads\Actions\CreateLead;
use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadNote;
use App\Domain\Leads\Support\LeadDuplicates;
use App\Domain\Leads\Support\PhoneDigits;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Leads\LeadTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LeadTestHelpers::class);

beforeEach(fn () => Mail::fake());

test('a lead from the public form is new, cleaned, on the timeline and audited', function () {
    $lead = $this->makeLead();

    expect($lead->status)->toBe(LeadStatus::New)
        ->and($lead->postcode)->toBe('LS6 2AB')
        ->and($lead->phone_digits)->toBe('07700900123')
        ->and($lead->assigned_admin_id)->toBeNull();

    $note = LeadNote::query()->where('lead_id', $lead->id)->sole();
    expect($note->kind)->toBe(LeadNoteKind::Created)
        ->and($note->admin_id)->toBeNull()
        ->and($note->body)->toBe('Trial request received from the website');

    $audit = AuditLog::query()->where('action', 'lead.created')->sole();
    expect($audit->subject_id)->toBe($lead->id)
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->company_id)->toBeNull()
        ->and($audit->after['shops_count'])->toBe(1);
});

test('an admin-added lead can be assigned with a follow-up, and says who added it', function () {
    $sam = $this->salesAdmin();
    $at = now()->addDay()->startOfHour();

    $this->actingAs($sam, 'admin');
    $lead = app(CreateLead::class)->handle($this->leadDetails(), $sam, $at);

    expect($lead->assigned_admin_id)->toBe($sam->id)
        ->and($lead->follow_up_at->equalTo($at))->toBeTrue();

    $notes = LeadNote::query()->where('lead_id', $lead->id)->get();
    expect($notes->pluck('kind')->all())->toContain(LeadNoteKind::Created, LeadNoteKind::Assigned)
        ->and($notes->firstWhere('kind', LeadNoteKind::Created)->body)->toBe('Added the lead (website)')
        ->and($notes->pluck('admin_id')->unique()->all())->toBe([$sam->id]);

    Mail::assertQueued(AdminNewLeadMail::class, fn (AdminNewLeadMail $mail) => $mail->data->addedBy === 'Sam Sales');
});

test('staff are emailed once per new lead, to every address in the alert list', function () {
    config(['sspos.lead_alert_emails' => ['sales@switchandsave.test', 'owner@switchandsave.test']]);

    $lead = $this->makeLead();

    Mail::assertQueued(AdminNewLeadMail::class, 1);
    Mail::assertQueued(AdminNewLeadMail::class, function (AdminNewLeadMail $mail) use ($lead) {
        $recipients = array_map(fn ($address) => $address->address, $mail->envelope()->to);

        return $recipients === ['sales@switchandsave.test', 'owner@switchandsave.test']
            && $mail->data->leadId === $lead->id
            && $mail->data->businessName === 'Patel News & Booze'
            && $mail->data->possibleDuplicate === null;
    });
});

test('with no alert list the staff email falls back to the staff inbox, linking to the lead', function () {
    config(['sspos.lead_alert_emails' => [], 'sspos.staff_email' => 'team@switchandsave.test', 'app.url' => 'https://portal.test']);

    $lead = $this->makeLead();

    Mail::assertQueued(AdminNewLeadMail::class, function (AdminNewLeadMail $mail) use ($lead) {
        $mail->assertSeeInHtml('https://portal.test/admin/leads/'.$lead->id);

        return $mail->envelope()->to[0]->address === 'team@switchandsave.test';
    });
});

test('a lead needs a business, a contact, and an email or phone', function (array $override, string $field) {
    $details = array_merge(['business' => 'Patel News', 'email' => 'a@b.test', 'phone' => null], $override);

    expect(fn () => app(CreateLead::class)->handle($this->leadDetails($details['business'], $details['email'], $details['phone'])))
        ->toThrow(ValidationException::class);

    try {
        app(CreateLead::class)->handle($this->leadDetails($details['business'], $details['email'], $details['phone']));
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(Lead::query()->count())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'no business' => [['business' => '  '], 'business_name'],
    'no way to reach them' => [['email' => null, 'phone' => null], 'email'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
]);

test('assigning to staff who cannot work leads is refused', function () {
    $support = $this->admin(AdminRole::Support);

    expect(fn () => app(CreateLead::class)->handle($this->leadDetails(), $support))->toThrow(ValidationException::class);
});

test('phone numbers compare in any common UK format', function (string $typed) {
    expect(PhoneDigits::from($typed))->toBe('07700900123');
})->with(['07700 900123', '+44 7700 900123', '+44 (0)7700 900-123', '0044 7700 900123', '(07700) 900123']);

test('possible duplicates are flagged against other leads by email and by phone', function () {
    $first = $this->makeLead(details: $this->leadDetails('Patel News', 'imran@patelnews.test', '07700 900123'));
    $byEmail = $this->makeLead(details: $this->leadDetails('Patel News Leeds', 'IMRAN@patelnews.test', null));
    $byPhone = $this->makeLead(details: $this->leadDetails('Patel Booze', 'other@patel.test', '+44 7700 900123'));
    $unrelated = $this->makeLead(details: $this->leadDetails('Singh Stores', 'harpreet@singh.test', '07700 900456'));

    $emailMatch = LeadDuplicates::for($byEmail);
    expect($emailMatch)->toHaveCount(1)
        ->and($emailMatch[0]->type)->toBe('lead')
        ->and($emailMatch[0]->id)->toBe($first->id)
        ->and($emailMatch[0]->matchedOn)->toBe(['email']);

    expect(collect(LeadDuplicates::for($byPhone))->pluck('id')->all())->toContain($first->id)
        ->and(LeadDuplicates::for($unrelated))->toBe([]);

    $note = LeadNote::query()->where('lead_id', $byEmail->id)->where('kind', LeadNoteKind::Duplicate)->sole();
    expect($note->body)->toBe('Possible duplicate: same email as lead Patel News')
        ->and($note->meta['matches'][0]['id'])->toBe($first->id);

    Mail::assertQueued(AdminNewLeadMail::class, fn (AdminNewLeadMail $mail) => $mail->data->businessName === 'Patel News Leeds'
        && $mail->data->possibleDuplicate === 'Same email as lead Patel News');
    expect(AuditLog::query()->where('action', 'lead.created')->where('subject_id', $byEmail->id)->sole()->meta['possible_duplicates'])->toBe(1);
});

test('possible duplicates are flagged against tenants: business email, business phone and portal users', function () {
    $tenant = $this->tenant('Khan Mini Mart', ownerEmail: 'aisha@khan.test');
    $tenant->forceFill(['phone' => '0113 496 0000', 'email' => 'shop@khan.test'])->save();

    $byOwner = LeadDuplicates::for($this->makeLead(details: $this->leadDetails('Khan 2', 'aisha@khan.test', null)));
    $byBusinessEmail = LeadDuplicates::for($this->makeLead(details: $this->leadDetails('Khan 3', 'SHOP@khan.test', null)));
    $byPhone = LeadDuplicates::for($this->makeLead(details: $this->leadDetails('Khan 4', null, '+44 113 496 0000')));

    foreach ([$byOwner, $byBusinessEmail, $byPhone] as $matches) {
        $tenants = array_values(array_filter($matches, fn ($m) => $m->type === 'tenant'));
        expect($tenants)->toHaveCount(1)->and($tenants[0]->id)->toBe($tenant->id)->and($tenants[0]->status)->toBe('trial');
    }

    expect($byPhone[0]->matchedOn)->toBe(['phone'])
        ->and(Company::query()->count())->toBe(1);
});
