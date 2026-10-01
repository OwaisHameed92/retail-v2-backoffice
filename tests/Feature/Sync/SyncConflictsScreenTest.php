<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Sync\Models\SyncConflict;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;
use Tests\Feature\TillData\TillFixtures;

uses(TenancyTestHelpers::class);

/** Module 2.9B: the tenant portal's sync conflicts screen (`/app/sync/*`, `company.can:sync.manage`). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-09-29 10:00:00');
    $product = TillFixtures::sample('entities/Product.json');

    // A real hubEditNewer from Leeds, plus a historic-record conflict from Bradford.
    Pull::portalCreate($this->company, 'Product', $product);
    $this->travel(5)->minutes();
    Pull::portalUpdate($this->company, 'Product', $product['id'], ['name' => 'Portal name']);
    $edit = [...$product, 'name' => 'Shop name', 'rowVersion' => 2, 'updatedAt' => '2026-09-29T10:02:00Z'];
    $this->sync->push([TillFixtures::envelope('Product', $edit, 1, ['op' => 'U', 'at' => '2026-09-29T10:02:00Z'])])->assertOk();
    $this->hub = SyncConflict::withoutCompanyScope()->sole();
    $this->historic = Pull::conflict($this->company, ['branch_id' => $this->sync->bradford->id]);

    // A clash the Bradford till recorded, with the portal's row it kept aside (hubChange).
    $hubChange = Pull::changes($this->sync->pull(0, bradford: true))[0];
    $clash = Pull::payload('SyncConflict', '01K5VB0000000000000SC00001', [
        'entity' => 'Product', 'entityId' => $product['id'], 'ownership' => 'hubOwned', 'resolution' => 'pending', 'resolvedAt' => null,
        'hubChange' => json_encode($hubChange), 'detail' => 'Changed here and at head office', 'branchId' => TillFixtures::BRADFORD,
    ]);
    $this->sync->push([TillFixtures::envelope('SyncConflict', $clash, 1)], bradford: true)->assertOk();

    $this->owner = $this->memberOf($this->company, CompanyRole::Owner);
});

test('guests are sent to the login page', function () {
    $this->get('/app/sync/conflicts')->assertRedirect('/login');
    $this->get("/app/sync/conflicts/{$this->hub->id}")->assertRedirect('/login');
    $this->post("/app/sync/conflicts/{$this->hub->id}/resolve", ['resolution' => 'keepPortal'])->assertRedirect('/login');
    $this->get('/app/sync/clashes/01K5VB0000000000000SC00001')->assertRedirect('/login');
});

test('staff and accountants get 403 everywhere; nothing is resolved', function (CompanyRole $role) {
    $user = $this->memberOf($this->company, $role);

    $this->actingAs($user)->get('/app/sync/conflicts')->assertForbidden();
    $this->actingAs($user)->get("/app/sync/conflicts/{$this->hub->id}")->assertForbidden();
    $this->actingAs($user)->post("/app/sync/conflicts/{$this->hub->id}/resolve", ['resolution' => 'useTill'])->assertForbidden();
    $this->actingAs($user)->get('/app/sync/clashes/01K5VB0000000000000SC00001')->assertForbidden();

    expect($this->hub->fresh()->status)->toBe('open');
})->with([CompanyRole::Staff, CompanyRole::Accountant]);

test('owners and managers see the open conflicts, newest first, with counts, filters and the nav ability', function (CompanyRole $role) {
    $user = $role === CompanyRole::Owner ? $this->owner : $this->memberOf($this->company, $role);

    $this->actingAs($user)->get('/app/sync/conflicts')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/sync/conflicts')
        ->where('tab', 'portal')
        ->where('counts', ['open' => 2, 'resolved' => 0, 'shopPending' => 1])
        ->where('stats.hubRows', 1)
        ->where('stats.historic', 1)
        ->where('filters.status', 'open')
        ->has('conflicts.data', 2)
        ->where('conflicts.data.0.kind', 'immutableChange')
        ->where('conflicts.data.1.kind', 'hubEditNewer')
        ->where('conflicts.data.1.subject', 'Shop name')
        ->where('conflicts.data.1.branch', 'Leeds Kirkgate')
        ->has('options.branches', 2)
        ->where('abilities', fn ($abilities) => in_array('sync.manage', collect($abilities)->all(), true)));
})->with([CompanyRole::Owner, CompanyRole::Manager]);

test('filters: by reason, shop, status and search', function () {
    $list = fn (string $query) => $this->actingAs($this->owner)->get("/app/sync/conflicts?{$query}")->assertOk();

    $list('kind=hubEditNewer')->assertInertia(fn ($p) => $p->has('conflicts.data', 1)->where('conflicts.data.0.id', $this->hub->id));
    $list("branch={$this->sync->bradford->id}")->assertInertia(fn ($p) => $p->has('conflicts.data', 1)->where('conflicts.data.0.id', $this->historic->id));
    $list('search=SR001000482')->assertInertia(fn ($p) => $p->has('conflicts.data', 1));
    $list('status=resolved')->assertInertia(fn ($p) => $p->has('conflicts.data', 0));
    $list('kind=nonsense&status=nonsense')->assertInertia(fn ($p) => $p->has('conflicts.data', 2)->where('filters.kind', null));
});

test('the review page compares the portal\'s row with the shop\'s, field by field, and offers the right choices', function () {
    $this->actingAs($this->owner)->get("/app/sync/conflicts/{$this->hub->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/sync/conflict')
        ->where('conflict.kind', 'hubEditNewer')
        ->where('conflict.rowExists', true)
        ->where('fields', fn ($fields) => collect($fields)->where('changed', true)->values()->all() === [
            ['key' => 'name', 'label' => 'Name', 'portal' => 'Portal name', 'till' => 'Shop name', 'changed' => true],
        ])
        ->where('resolutions', [['value' => 'keepPortal', 'label' => 'Kept the portal\'s version'], ['value' => 'useTill', 'label' => 'Used the shop\'s version']]));

    $this->actingAs($this->owner)->get("/app/sync/conflicts/{$this->historic->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('resolutions', [['value' => 'acknowledged', 'label' => 'Acknowledged']])->where('conflict.rowExists', false));
});

test('resolving: the shop\'s version is taken, the page says so, and a second attempt is refused', function () {
    $this->actingAs($this->owner)->post("/app/sync/conflicts/{$this->hub->id}/resolve", ['resolution' => 'useTill', 'note' => 'Shop was right'])
        ->assertRedirect("/app/sync/conflicts/{$this->hub->id}")
        ->assertSessionHas('success');

    expect(DB::table('products')->value('name'))->toBe('Shop name')
        ->and($this->hub->fresh()->resolved_by)->toBe((string) $this->owner->id);

    $this->actingAs($this->owner)->post("/app/sync/conflicts/{$this->hub->id}/resolve", ['resolution' => 'keepPortal'])
        ->assertSessionHasErrors('conflict');

    $this->actingAs($this->owner)->get("/app/sync/conflicts/{$this->hub->id}")
        ->assertInertia(fn ($page) => $page->where('conflict.status', 'resolved')->where('conflict.resolvedBy', $this->owner->name)
            ->where('conflict.resolutionNote', 'Shop was right')->where('resolutions', []));
});

test('resolving is validated: a choice is required and must fit the kind of conflict', function () {
    $this->actingAs($this->owner)->post("/app/sync/conflicts/{$this->hub->id}/resolve", [])->assertSessionHasErrors('resolution');
    $this->actingAs($this->owner)->post("/app/sync/conflicts/{$this->hub->id}/resolve", ['resolution' => 'deleteEverything'])->assertSessionHasErrors('resolution');
    $this->actingAs($this->owner)->post("/app/sync/conflicts/{$this->historic->id}/resolve", ['resolution' => 'useTill'])->assertSessionHasErrors('resolution');

    expect($this->historic->fresh()->status)->toBe('open');
});

test('the shop clashes tab lists what waits at the tills; a clash shows the change the till kept aside', function () {
    $this->actingAs($this->owner)->get('/app/sync/conflicts?tab=shop')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('tab', 'shop')
        ->where('filters.resolution', 'pending')
        ->has('clashes.data', 1)
        ->where('clashes.data.0.branch', 'Bradford')
        ->where('clashes.data.0.hasHubChange', true)
        ->missing('conflicts'));

    $this->actingAs($this->owner)->get('/app/sync/conflicts?tab=shop&resolution=hubWins')->assertInertia(fn ($page) => $page->has('clashes.data', 0));

    $this->actingAs($this->owner)->get('/app/sync/clashes/01K5VB0000000000000SC00001')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/sync/clash')
        ->where('clash.resolution', 'pending')
        ->where('clash.subject', 'Portal name')
        ->where('hubChange.op', 'U')
        ->where('fields', fn ($fields) => collect($fields)->where('changed', true)->isEmpty()));
});

test('tenant isolation: another business\'s owner never sees or settles these conflicts', function () {
    $other = Company::factory()->create();
    Branch::factory()->forCompany($other)->create(['code' => 'OTH']);
    $theirs = Pull::conflict($other, []);
    $stranger = $this->memberOf($other, CompanyRole::Owner);

    $this->actingAs($stranger)->get('/app/sync/conflicts')->assertOk()
        ->assertInertia(fn ($page) => $page->has('conflicts.data', 1)->where('conflicts.data.0.id', $theirs->id)->where('counts.open', 1));
    $this->actingAs($stranger)->get("/app/sync/conflicts/{$this->hub->id}")->assertNotFound();
    $this->actingAs($stranger)->post("/app/sync/conflicts/{$this->hub->id}/resolve", ['resolution' => 'useTill'])->assertNotFound();
    $this->actingAs($stranger)->get('/app/sync/clashes/01K5VB0000000000000SC00001')->assertNotFound();

    expect($this->hub->fresh()->status)->toBe('open')->and(DB::table('products')->value('name'))->toBe('Portal name');
});

test('a user with no business is never let in', function () {
    $this->actingAs(User::factory()->create())->get('/app/sync/conflicts')->assertRedirect(route('login'));
});

test('security review M1: a one-shop manager sees only their shop\'s conflicts and clashes and cannot settle any', function () {
    $manager = $this->memberOf($this->company, CompanyRole::Manager);
    $this->company->users()->updateExistingPivot($manager->id, ['branch_id' => $this->sync->bradford->id]);

    $this->actingAs($manager)->get('/app/sync/conflicts?branch='.$this->sync->leeds->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('counts', ['open' => 1, 'resolved' => 0, 'shopPending' => 1])
        ->where('filters.branch', $this->sync->bradford->id)
        ->has('conflicts.data', 1)
        ->where('conflicts.data.0.id', $this->historic->id)
        ->has('options.branches', 1));

    // Leeds' conflict is not found; Bradford's shows without the settle options; nothing can be settled.
    $this->actingAs($manager)->get("/app/sync/conflicts/{$this->hub->id}")->assertNotFound();
    $this->actingAs($manager)->post("/app/sync/conflicts/{$this->hub->id}/resolve", ['resolution' => 'useTill'])->assertForbidden();
    $this->actingAs($manager)->get("/app/sync/conflicts/{$this->historic->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('resolutions', []));
    $this->actingAs($manager)->post("/app/sync/conflicts/{$this->historic->id}/resolve", ['resolution' => 'acknowledged'])->assertForbidden();
    $this->actingAs($manager)->get('/app/sync/clashes/01K5VB0000000000000SC00001')->assertOk();

    expect($this->hub->fresh()->status)->toBe('open')->and($this->historic->fresh()->status)->toBe('open');
});
