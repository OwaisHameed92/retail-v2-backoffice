<?php

use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Queries\LeadStats;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use App\Domain\Mail\Mailables\LeadRejectedMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Leads\LeadTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LeadTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->sam = $this->salesAdmin();
});

/** Ids on the list for a query string, in order. */
function listedIds(object $test, string $query = ''): array
{
    $ids = [];
    $test->actingAs($test->sam, 'admin')->get('/admin/leads'.$query)->assertOk()
        ->assertInertia(function (Assert $page) use (&$ids) {
            $ids = array_column($page->toArray()['props']['leads']['data'], 'id');
        });

    return $ids;
}

test('the list shows leads with counts, stats and options', function () {
    $lead = Lead::factory()->assignedTo($this->sam)->create(['business_name' => 'Patel News']);
    Lead::factory()->contacted()->create();
    Lead::factory()->rejected()->create();
    Lead::factory()->create()->delete();

    $this->actingAs($this->sam, 'admin')->get('/admin/leads')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/leads/index')
            ->where('view', 'list')
            ->where('board', null)
            ->has('leads.data', 3)
            ->where('leads.meta.total', 3)
            ->where('counts.new', 1)
            ->where('counts.open', 2)
            ->where('counts.archived', 1)
            ->where('mine', 1)
            ->where('stats.awaitingContact', 1)
            ->where('can.create', true)
            ->has('options.admins', 1)
            ->where('options.admins.0.value', $this->sam->id)
            ->where('leads.data.2.id', $lead->id)
            ->where('leads.data.2.assignedAdmin.name', 'Sam Sales'));
});

test('filters: status, open, archived, source, assigned and follow-up', function () {
    $now = CarbonImmutable::now();
    $amy = $this->salesAdmin('Amy');
    $new = Lead::factory()->assignedTo($this->sam)->followUpAt($now->subHour())->create(['source' => LeadSource::Phone]);
    $contacted = Lead::factory()->contacted()->assignedTo($amy)->followUpAt($now->addDays(3))->create();
    $rejected = Lead::factory()->rejected()->create(['source' => LeadSource::Referral]);
    $archived = Lead::factory()->create();
    $archived->delete();
    $later = Lead::factory()->followUpAt($now->addDays(20))->create();

    expect(listedIds($this, '?status=new'))->toEqualCanonicalizing([$new->id, $later->id])
        ->and(listedIds($this, '?status=open'))->toEqualCanonicalizing([$new->id, $contacted->id, $later->id])
        ->and(listedIds($this, '?status=rejected'))->toBe([$rejected->id])
        ->and(listedIds($this, '?status=archived'))->toBe([$archived->id])
        ->and(listedIds($this, '?source=phone'))->toBe([$new->id])
        ->and(listedIds($this, '?assigned=me'))->toBe([$new->id])
        ->and(listedIds($this, '?assigned='.$amy->id))->toBe([$contacted->id])
        ->and(listedIds($this, '?assigned=none'))->toEqualCanonicalizing([$rejected->id, $later->id])
        ->and(listedIds($this, '?followUp=overdue'))->toBe([$new->id])
        ->and(listedIds($this, '?followUp=due'))->toBe([$new->id])
        ->and(listedIds($this, '?followUp=week'))->toBe([$contacted->id])
        ->and(listedIds($this, '?followUp=none'))->toEqualCanonicalizing([$rejected->id])
        ->and(listedIds($this, '?status=bogus'))->toHaveCount(4);
});

test('search matches business, contact, email, town, postcode and phone in any format', function () {
    $patel = Lead::factory()->create(['business_name' => 'Patel News', 'contact_name' => 'Imran Patel', 'email' => 'imran@patel.test', 'phone' => '07700 900123', 'town' => 'Leeds', 'postcode' => 'LS6 2AB']);
    Lead::factory()->create(['business_name' => 'Singh Stores', 'contact_name' => 'Harpreet Singh', 'email' => 'h@singh.test', 'phone' => '07700 900456', 'town' => 'Wolverhampton', 'postcode' => 'WV1 4AN']);

    foreach (['Patel', 'imran@', 'Leeds', 'LS6', '900123', '+44 7700 900123'] as $term) {
        expect(listedIds($this, '?search='.urlencode($term)))->toBe([$patel->id]);
    }
});

test('sorting by follow-up puts leads without one last', function () {
    $none = Lead::factory()->create();
    $later = Lead::factory()->followUpAt(now()->addDays(5))->create();
    $soon = Lead::factory()->followUpAt(now()->addDay())->create();

    expect(listedIds($this, '?sort=follow_up_at&direction=asc'))->toBe([$soon->id, $later->id, $none->id])
        ->and(listedIds($this, '?sort=follow_up_at&direction=desc'))->toBe([$later->id, $soon->id, $none->id])
        ->and(listedIds($this, '?sort=business_name&direction=asc'))->toHaveCount(3);
});

test('the board groups leads by status with totals, respecting the other filters', function () {
    Lead::factory()->count(2)->create();
    Lead::factory()->contacted()->assignedTo($this->sam)->create();
    Lead::factory()->rejected()->create();

    $this->actingAs($this->sam, 'admin')->get('/admin/leads?view=board')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'board')
            ->where('leads', null)
            ->has('board', 4)
            ->where('board.0.status', 'new')
            ->where('board.0.total', 2)
            ->has('board.0.leads', 2)
            ->where('board.1.status', 'contacted')
            ->where('board.1.total', 1)
            ->where('board.2.status', 'converted')
            ->where('board.3.total', 1));

    $this->actingAs($this->sam, 'admin')->get('/admin/leads?view=board&assigned=me')
        ->assertInertia(fn (Assert $page) => $page->where('board.0.total', 0)->where('board.1.total', 1));
});

test('the lead page shows the lead, its timeline, duplicates and the approval suggestion', function () {
    $this->standardPlan();
    $this->actingAs($this->sam, 'admin');
    $first = $this->makeLead(details: $this->leadDetails('Patel News', 'imran@patel.test', null));
    $lead = $this->makeLead(details: $this->leadDetails('Patel News Leeds', 'imran@patel.test', null, shops: 2, tills: 3));

    $this->get("/admin/leads/{$lead->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/leads/show')
            ->where('lead.businessName', 'Patel News Leeds')
            ->where('lead.status', 'new')
            ->has('notes', 2)
            ->where('notes.0.kind', 'duplicate')
            ->where('duplicates.0.id', $first->id)
            ->where('duplicates.0.type', 'lead')
            ->has('approval.suggestion.shops', 2)
            ->where('approval.suggestion.shops.0.tills', 2)
            ->where('approval.ownerHasLogin', false)
            ->where('approval.trialDays', 7)
            ->where('can.approve', true));
});

test('an archived lead can still be opened and restored', function () {
    $lead = Lead::factory()->create();
    $lead->delete();

    $this->actingAs($this->sam, 'admin')->get("/admin/leads/{$lead->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('lead.archived', true)->where('approval', null));

    $this->actingAs($this->sam, 'admin')->post("/admin/leads/{$lead->id}/restore")->assertRedirect(route('admin.leads.show', $lead->id));
    expect($lead->fresh()->trashed())->toBeFalse();
});

test('adding a lead through the form validates, saves and emails the team', function () {
    $this->actingAs($this->sam, 'admin')->post('/admin/leads', [
        'business_name' => '',
        'contact_name' => 'Imran',
        'email' => '',
        'phone' => '',
        'shops_count' => 0,
        'tills_count' => 1,
        'business_type' => 'convenience',
        'source' => 'phone',
    ])->assertSessionHasErrors(['business_name', 'email', 'phone', 'shops_count']);

    $response = $this->actingAs($this->sam, 'admin')->post('/admin/leads', [
        'business_name' => 'Patel News',
        'contact_name' => 'Imran Patel',
        'email' => 'Imran@Patel.test ',
        'phone' => '07700 900123',
        'town' => 'Leeds',
        'postcode' => 'ls62ab',
        'shops_count' => 2,
        'tills_count' => 3,
        'business_type' => 'offLicence',
        'current_system' => '',
        'message' => 'Called in after seeing the van.',
        'source' => 'walkIn',
        'consent_marketing' => true,
        'assigned_admin_id' => $this->sam->id,
        'follow_up_date' => now('Europe/London')->addDay()->toDateString(),
        'follow_up_time' => '14:30',
    ]);

    $lead = Lead::query()->sole();
    $response->assertRedirect(route('admin.leads.show', $lead))->assertSessionHas('success');

    expect($lead->email)->toBe('imran@patel.test')
        ->and($lead->postcode)->toBe('LS6 2AB')
        ->and($lead->current_system)->toBeNull()
        ->and($lead->source)->toBe(LeadSource::WalkIn)
        ->and($lead->assigned_admin_id)->toBe($this->sam->id)
        ->and($lead->follow_up_at->setTimezone('Europe/London')->format('H:i'))->toBe('14:30');
    Mail::assertQueued(AdminNewLeadMail::class, 1);
});

test('editing a lead through the form saves; converted leads redirect to the lead', function () {
    $lead = Lead::factory()->create(['business_name' => 'Old Name']);
    $payload = ['business_name' => 'New Name', 'contact_name' => 'Imran', 'email' => 'a@b.test', 'shops_count' => 1, 'tills_count' => 1, 'business_type' => 'grocery', 'source' => 'website'];

    $this->actingAs($this->sam, 'admin')->get("/admin/leads/{$lead->id}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/leads/edit')->where('form.business_name', 'Old Name'));
    $this->actingAs($this->sam, 'admin')->put("/admin/leads/{$lead->id}", $payload)->assertRedirect(route('admin.leads.show', $lead));
    expect($lead->fresh()->business_name)->toBe('New Name');

    $lead->forceFill(['status' => LeadStatus::Converted])->save();
    $this->actingAs($this->sam, 'admin')->get("/admin/leads/{$lead->id}/edit")->assertRedirect(route('admin.leads.show', $lead));
    $this->actingAs($this->sam, 'admin')->put("/admin/leads/{$lead->id}", $payload)->assertSessionHasErrors('status');
});

test('the working routes change the lead and say so', function () {
    $lead = Lead::factory()->create(['email' => 'imran@patel.test']);
    $as = $this->actingAs($this->sam, 'admin')->from("/admin/leads/{$lead->id}");

    $as->post("/admin/leads/{$lead->id}/notes", ['body' => 'Left a voicemail'])->assertRedirect("/admin/leads/{$lead->id}")->assertSessionHas('success', 'Note added.');
    $as->post("/admin/leads/{$lead->id}/notes", ['body' => ''])->assertSessionHasErrors('body');
    $as->post("/admin/leads/{$lead->id}/assign", ['admin_id' => $this->sam->id])->assertSessionHas('success', 'Assigned to Sam Sales.');
    $as->post("/admin/leads/{$lead->id}/follow-up", ['date' => now()->subDays(2)->toDateString()])->assertSessionHasErrors('date');
    $as->post("/admin/leads/{$lead->id}/follow-up", ['date' => now('Europe/London')->addDays(2)->toDateString(), 'time' => '10:15', 'note' => 'After the delivery'])->assertSessionHas('success');
    $as->post("/admin/leads/{$lead->id}/contacted", ['note' => 'Spoke to him'])->assertSessionHas('success', 'Marked as contacted.');
    $as->post("/admin/leads/{$lead->id}/reject", ['reason' => 'x'])->assertSessionHasErrors('reason');
    $as->post("/admin/leads/{$lead->id}/reject", ['reason' => 'Not a retailer', 'notify' => true])->assertSessionHas('success', 'Lead rejected. We have emailed them a short note.');
    $as->post("/admin/leads/{$lead->id}/reopen")->assertSessionHas('success', 'Lead reopened.');
    $this->actingAs($this->sam, 'admin')->delete("/admin/leads/{$lead->id}")->assertRedirect(route('admin.leads.index'));

    expect(Lead::withTrashed()->find($lead->id)->status)->toBe(LeadStatus::Contacted)
        ->and(Lead::query()->find($lead->id))->toBeNull();
    Mail::assertQueued(LeadRejectedMail::class, 1);
});

test('the approval route validates the dialog and a second approval is refused', function () {
    $this->standardPlan();
    $lead = Lead::factory()->create();
    $as = $this->actingAs($this->sam, 'admin')->from("/admin/leads/{$lead->id}");

    $as->post("/admin/leads/{$lead->id}/approve", ['shops' => [
        ['name' => 'Leeds', 'code' => 'lds', 'nation' => 'england', 'tills' => 2],
        ['name' => 'Leeds 2', 'code' => 'LDS', 'nation' => 'england', 'tills' => 1],
    ]])->assertSessionHasErrors(['shops.1.code']);

    $as->post("/admin/leads/{$lead->id}/approve", ['shops' => [['name' => 'Leeds', 'code' => 'LDS', 'nation' => 'wales', 'tills' => 2]]])
        ->assertRedirect(route('admin.tenants.show', $lead->fresh()->company_id))
        ->assertSessionHas('success');

    $as->post("/admin/leads/{$lead->id}/approve", ['shops' => [['name' => 'Leeds', 'code' => 'LDX', 'nation' => 'england', 'tills' => 1]]])
        ->assertRedirect("/admin/leads/{$lead->id}")
        ->assertSessionHasErrors('status');

    $this->actingAs($this->sam, 'admin')->get("/admin/leads/{$lead->id}")
        ->assertInertia(fn (Assert $page) => $page->where('lead.status', 'converted')->where('lead.company.id', $lead->fresh()->company_id)->where('approval', null));
});

test('lead stats count the week, awaiting contact, follow-ups due and conversion', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00', 'Europe/London')); // a Thursday
    $monday = CarbonImmutable::parse('2026-09-21 00:30', 'Europe/London');

    Lead::factory()->create(['created_at' => $monday]);                                                   // new, this week
    Lead::factory()->followUpAt(now()->subHour())->create(['created_at' => $monday->subDays(2)]);          // new, overdue
    Lead::factory()->contacted()->followUpAt(now()->setTimezone('Europe/London')->setTime(18, 0))->create(['created_at' => now()->subDays(10)]); // due later today
    Lead::factory()->contacted()->followUpAt(now()->addDays(2))->create(['created_at' => now()->subDays(20)]);
    Lead::factory()->status(LeadStatus::Converted)->create(['created_at' => now()->subDays(30)]);
    Lead::factory()->status(LeadStatus::Converted)->followUpAt(now()->subDay())->create(['created_at' => now()->subDays(200)]); // outside the window, not open
    Lead::factory()->rejected()->create(['created_at' => now()->subDays(40)]);
    Lead::factory()->create(['created_at' => now()])->delete();                                            // archived: ignored

    $stats = LeadStats::compute()->toArray();

    expect($stats)->toMatchArray([
        'newThisWeek' => 1,
        'awaitingContact' => 2,
        'followUpsDue' => 2,
        'overdueFollowUps' => 1,
        'receivedInWindow' => 6,
        'convertedInWindow' => 1,
        'conversionRate' => 16.7,
        'windowDays' => 90,
    ]);

    Lead::query()->forceDelete();
    expect(LeadStats::compute()->conversionRate)->toBeNull();
});
