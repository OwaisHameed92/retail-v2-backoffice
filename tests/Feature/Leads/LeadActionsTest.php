<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Leads\Actions\AddLeadNote;
use App\Domain\Leads\Actions\ApproveTrial;
use App\Domain\Leads\Actions\ArchiveLead;
use App\Domain\Leads\Actions\AssignLead;
use App\Domain\Leads\Actions\MarkContacted;
use App\Domain\Leads\Actions\RejectLead;
use App\Domain\Leads\Actions\ReopenLead;
use App\Domain\Leads\Actions\RestoreLead;
use App\Domain\Leads\Actions\SetFollowUp;
use App\Domain\Leads\Actions\UpdateLead;
use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadNote;
use App\Domain\Mail\Mailables\LeadRejectedMail;
use App\Domain\Shared\Models\AuditLog;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Leads\LeadTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LeadTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->sam = $this->salesAdmin();
    $this->actingAs($this->sam, 'admin');
    $this->lead = $this->makeLead();
});

function timeline(Lead $lead, ?LeadNoteKind $kind = null): array
{
    return LeadNote::query()->where('lead_id', $lead->id)
        ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
        ->orderBy('created_at')->orderBy('id')->pluck('body')->all();
}

function audits(string $action): int
{
    return AuditLog::query()->where('action', $action)->count();
}

test('staff notes go on the timeline with their author; the audit log records the note, not its text', function () {
    $note = app(AddLeadNote::class)->handle($this->lead, "  Called, owner is away until Monday.  \n");

    expect($note->body)->toBe('Called, owner is away until Monday.')
        ->and($note->kind)->toBe(LeadNoteKind::Note)
        ->and($note->admin_id)->toBe($this->sam->id);

    $audit = AuditLog::query()->where('action', 'lead.note_added')->sole();
    expect($audit->meta)->toMatchArray(['note_id' => $note->id])
        ->and(json_encode($audit->toArray()))->not->toContain('owner is away');

    expect(fn () => app(AddLeadNote::class)->handle($this->lead, '   '))->toThrow(ValidationException::class)
        ->and(fn () => app(AddLeadNote::class)->handle($this->lead, str_repeat('a', 5001)))->toThrow(ValidationException::class);
});

test('editing writes only real changes, re-checks duplicates, and is refused once converted', function () {
    $other = $this->makeLead(details: $this->leadDetails('Other', 'dup@other.test', '07700 900555'));

    app(UpdateLead::class)->handle($this->lead, $this->leadDetails());
    expect(audits('lead.updated'))->toBe(0);

    app(UpdateLead::class)->handle($this->lead, $this->leadDetails(email: 'dup@other.test', tills: 4));

    $audit = AuditLog::query()->where('action', 'lead.updated')->sole();
    expect($audit->before)->toEqual(['email' => 'imran@patelnews.test', 'tills_count' => 2])
        ->and($audit->after)->toEqual(['email' => 'dup@other.test', 'tills_count' => 4])
        ->and(timeline($this->lead, LeadNoteKind::Updated))->toBe(['Updated email, tills'])
        ->and(timeline($this->lead, LeadNoteKind::Duplicate))->toBe(['Possible duplicate: same email as lead Other']);

    $this->standardPlan();
    app(ApproveTrial::class)->handle($other, $this->trialSetup());
    expect(fn () => app(UpdateLead::class)->handle($other->fresh(), $this->leadDetails('Renamed', 'dup@other.test')))->toThrow(ValidationException::class);
});

test('assigning and unassigning are noted and audited; staff who cannot work leads are refused', function () {
    $amy = $this->salesAdmin('Amy Owner');

    app(AssignLead::class)->handle($this->lead, $amy);
    app(AssignLead::class)->handle($this->lead, $amy); // no-op
    app(AssignLead::class)->handle($this->lead, null);

    expect($this->lead->fresh()->assigned_admin_id)->toBeNull()
        ->and(timeline($this->lead, LeadNoteKind::Assigned))->toBe(['Assigned to Amy Owner', 'Unassigned (was Amy Owner)'])
        ->and(audits('lead.assigned'))->toBe(2);

    $support = $this->admin(AdminRole::Support);
    $inactive = $this->salesAdmin('Gone');
    $inactive->update(['is_active' => false]);

    expect(fn () => app(AssignLead::class)->handle($this->lead, $support))->toThrow(ValidationException::class)
        ->and(fn () => app(AssignLead::class)->handle($this->lead, $inactive))->toThrow(ValidationException::class);
});

test('a follow-up can be set with a reason and cleared', function () {
    $at = now()->addDays(2)->setTime(13, 30);

    app(SetFollowUp::class)->handle($this->lead, $at, 'Call after the cash and carry');
    expect($this->lead->fresh()->follow_up_at->equalTo($at))->toBeTrue();

    app(SetFollowUp::class)->handle($this->lead, null);
    app(SetFollowUp::class)->handle($this->lead, null); // already clear: no-op

    expect($this->lead->fresh()->follow_up_at)->toBeNull()
        ->and(timeline($this->lead, LeadNoteKind::FollowUp)[0])->toContain('Follow up on')->toContain('Call after the cash and carry')
        ->and(timeline($this->lead, LeadNoteKind::FollowUp)[1])->toBe('Cleared the follow-up')
        ->and(audits('lead.follow_up_set'))->toBe(2);
});

test('marking contacted moves new to contacted and clears a follow-up that was due', function () {
    app(SetFollowUp::class)->handle($this->lead, now()->subHour());

    app(MarkContacted::class)->handle($this->lead, 'Spoke to Imran');

    $lead = $this->lead->fresh();
    expect($lead->status)->toBe(LeadStatus::Contacted)
        ->and($lead->contacted_at)->not->toBeNull()
        ->and($lead->follow_up_at)->toBeNull()
        ->and(timeline($lead, LeadNoteKind::StatusChanged))->toBe(['Marked as contacted: Spoke to Imran']);

    $this->travel(1)->days();
    $next = now()->addDays(3)->startOfHour();
    app(MarkContacted::class)->handle($lead, null, $next);

    $lead->refresh();
    expect($lead->last_contacted_at->gt($lead->contacted_at))->toBeTrue()
        ->and($lead->follow_up_at->equalTo($next))->toBeTrue()
        ->and(timeline($lead, LeadNoteKind::StatusChanged)[1])->toStartWith('Contacted again. Next follow-up')
        ->and(AuditLog::query()->where('action', 'lead.contacted')->first()->after)->toBe(['status' => 'contacted']);
});

test('a future follow-up survives marking contacted', function () {
    $later = now()->addDays(5)->startOfHour();
    app(SetFollowUp::class)->handle($this->lead, $later);

    app(MarkContacted::class)->handle($this->lead);

    expect($this->lead->fresh()->follow_up_at->equalTo($later))->toBeTrue();
});

test('rejecting keeps the reason internal and emails the prospect only when asked', function () {
    app(RejectLead::class)->handle($this->lead, 'Wants a restaurant system');

    $lead = $this->lead->fresh();
    expect($lead->status)->toBe(LeadStatus::Rejected)
        ->and($lead->rejection_reason)->toBe('Wants a restaurant system')
        ->and($lead->rejected_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'lead.rejected')->sole()->meta)->toMatchArray(['reason' => 'Wants a restaurant system', 'emailed' => false]);
    Mail::assertNotQueued(LeadRejectedMail::class);

    $second = $this->makeLead(details: $this->leadDetails('Second', 'second@shop.test', null));
    app(RejectLead::class)->handle($second, 'Outside the UK', notifyProspect: true);

    Mail::assertQueued(LeadRejectedMail::class, 1);
    Mail::assertQueued(LeadRejectedMail::class, function (LeadRejectedMail $mail) {
        $html = $mail->render();

        return $mail->hasTo('second@shop.test')
            && str_contains($html, 'Hi Imran')
            && str_contains($html, 'Second')
            && ! str_contains($html, 'Outside the UK');
    });
    expect(timeline($second, LeadNoteKind::StatusChanged))->toBe(['Rejected: Outside the UK (emailed the prospect)']);
});

test('rejecting needs a reason, an open lead, and an email address to notify', function () {
    $phoneOnly = $this->makeLead(details: $this->leadDetails('Phone Only', null, '07700 900777'));

    expect(fn () => app(RejectLead::class)->handle($this->lead, ' x '))->toThrow(ValidationException::class)
        ->and(fn () => app(RejectLead::class)->handle($phoneOnly, 'Not a shop', true))->toThrow(ValidationException::class);

    app(RejectLead::class)->handle($this->lead, 'Not a shop');
    expect(fn () => app(RejectLead::class)->handle($this->lead->fresh(), 'Again'))->toThrow(ValidationException::class)
        ->and(fn () => app(MarkContacted::class)->handle($this->lead->fresh()))->toThrow(ValidationException::class);
    Mail::assertNotQueued(LeadRejectedMail::class);
});

test('a rejected lead can be reopened as new, or as contacted when we spoke to them', function () {
    app(RejectLead::class)->handle($this->lead, 'Wrong number');
    app(ReopenLead::class)->handle($this->lead->fresh());
    expect($this->lead->fresh()->status)->toBe(LeadStatus::New)
        ->and($this->lead->fresh()->rejection_reason)->toBeNull();

    app(MarkContacted::class)->handle($this->lead->fresh());
    app(RejectLead::class)->handle($this->lead->fresh(), 'Changed their mind');
    app(ReopenLead::class)->handle($this->lead->fresh());

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Contacted)
        ->and(AuditLog::query()->where('action', 'lead.reopened')->latest('id')->first()->before)->toMatchArray(['rejection_reason' => 'Changed their mind'])
        ->and(fn () => app(ReopenLead::class)->handle($this->lead->fresh()))->toThrow(ValidationException::class);
});

test('archiving hides a lead and restoring brings it back; converted leads cannot be archived', function () {
    app(ArchiveLead::class)->handle($this->lead);
    expect(Lead::query()->find($this->lead->id))->toBeNull()
        ->and(Lead::withTrashed()->find($this->lead->id)->status)->toBe(LeadStatus::New);

    app(RestoreLead::class)->handle(Lead::withTrashed()->findOrFail($this->lead->id));
    expect(Lead::query()->find($this->lead->id))->not->toBeNull()
        ->and(audits('lead.archived'))->toBe(1)
        ->and(audits('lead.restored'))->toBe(1)
        ->and(timeline($this->lead, LeadNoteKind::Updated))->toBe(['Archived the lead', 'Restored the lead']);

    $this->standardPlan();
    app(ApproveTrial::class)->handle($this->lead->fresh(), $this->trialSetup());
    expect(fn () => app(ArchiveLead::class)->handle($this->lead->fresh()))->toThrow(ValidationException::class);
});

test('notes, assignment and follow-ups still work on a converted lead', function () {
    $this->standardPlan();
    app(ApproveTrial::class)->handle($this->lead, $this->trialSetup());
    $lead = $this->lead->fresh();

    app(AddLeadNote::class)->handle($lead, 'Trial going well');
    app(SetFollowUp::class)->handle($lead, now()->addDays(5));
    app(AssignLead::class)->handle($lead, $this->sam);

    // Entries after conversion carry the company id, so they appear on the tenant's activity too.
    expect(AuditLog::query()->where('action', 'lead.note_added')->sole()->company_id)->toBe($lead->company_id);
});
