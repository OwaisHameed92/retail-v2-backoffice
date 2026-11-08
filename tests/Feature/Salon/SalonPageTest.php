<?php

use App\Domain\PortalUsers\Support\RoleMatrix;
use App\Domain\Salon\Models\SalonCalendarFeed;
use App\Domain\Tenancy\Actions\SwitchCurrentBranch;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Enums\CompanyRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Salon\SalonFixtures as S;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

/*
 * Salon W3b: the tenant "Salon" page (`/app/salon`, `salon.manage`: owner and manager), its feed switches, the
 * read-only online booking requests, and the admin tenant page's salon card. All behind SALON_ONLINE and only for a
 * business with a salon shop. "Now" is 8 Nov 2026 10:00 UTC.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(S::NOW);
    config(['salon.online' => true]);
    [$this->company, $this->leeds, $this->york] = S::business();
    $this->owner = S::member($this->company, CompanyRole::Owner);
    $this->priya = S::stylist($this->company, $this->leeds, 'Priya Patel', ['is_online_bookable' => true]);
    $this->sam = S::stylist($this->company, $this->york, 'Sam Lee', ['is_bookable' => false]);
    $this->cut = S::service($this->company, 'Cut and blow-dry');
    $this->props = fn ($user = null, string $query = '') => $this->actingAs($user ?? $this->owner)->get('/app/salon'.$query)->assertOk()->viewData('page')['props'];
});

test('guests sign in first; staff and accountants get 403; owners and managers see the page and its menu item', function () {
    $this->get('/app/salon')->assertRedirect('/login');
    $this->postJson('/app/salon/feeds', [])->assertUnauthorized();

    foreach ([CompanyRole::Staff, CompanyRole::Accountant] as $role) {
        $user = S::member($this->company, $role);
        $this->actingAs($user)->get('/app/salon')->assertForbidden();
        $this->actingAs($user)->postJson('/app/salon/feeds', ['shopId' => $this->leeds->id, 'userId' => $this->priya])->assertForbidden();
        $this->actingAs($user)->get('/app')->assertOk()->assertInertia(fn (Assert $page) => $page->where('abilities', fn ($a) => ! collect($a)->contains('salon.manage')));
    }

    foreach ([CompanyRole::Owner, CompanyRole::Manager] as $role) {
        $this->actingAs(S::member($this->company, $role))->get('/app/salon')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('app/salon/index')->where('abilities', fn ($a) => collect($a)->contains('salon.manage')));
    }

    expect(collect(RoleMatrix::rows($this->company))->firstWhere('key', 'salon.manage'))
        ->toMatchArray(['roles' => ['owner' => true, 'manager' => true, 'accountant' => false, 'staff' => false]]);
});

test('switch off: no menu item, no role row, the page and feed calls 404', function () {
    config(['salon.online' => false]);
    $manager = S::member($this->company, CompanyRole::Manager);

    $this->actingAs($this->owner)->get('/app/salon')->assertNotFound();
    $this->actingAs($manager)->get('/app/salon')->assertNotFound();
    $this->actingAs($this->owner)->postJson('/app/salon/feeds', ['shopId' => $this->leeds->id, 'userId' => $this->priya])->assertNotFound();
    $this->actingAs($this->owner)->postJson('/app/salon/feeds', [])->assertNotFound();
    $this->actingAs($this->owner)->deleteJson('/app/salon/feeds/01K9SA0000000000000000FEED')->assertNotFound();
    $this->actingAs($this->owner)->get('/app')->assertOk()->assertInertia(fn (Assert $page) => $page->where('abilities', fn ($a) => ! collect($a)->contains('salon.manage')));

    expect(collect(RoleMatrix::rows($this->company))->pluck('key'))->not->toContain('salon.manage')
        ->and(collect(RoleMatrix::rows())->pluck('key'))->not->toContain('salon.manage')
        ->and(SalonCalendarFeed::withoutCompanyScope()->count())->toBe(0);
});

test('a grocery business sees nothing salon, even with the switch on', function () {
    [$grocery, $shop] = S::business('Corner Shop', BusinessType::GroceryHalalButcher);
    $owner = S::member($grocery, CompanyRole::Owner);

    $this->actingAs($owner)->get('/app/salon')->assertNotFound();
    $this->actingAs($owner)->postJson('/app/salon/feeds', ['shopId' => $shop->id, 'userId' => $this->priya])->assertNotFound();
    $this->actingAs($owner)->get('/app')->assertOk()->assertInertia(fn (Assert $page) => $page->where('abilities', fn ($a) => ! collect($a)->contains('salon.manage')));
    $this->actingAs($this->admin(), 'admin')->get("/admin/tenants/{$grocery->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('salon', null));

    expect(collect(RoleMatrix::rows($grocery))->pluck('key'))->not->toContain('salon.manage');
});

test('the page lists each salon shop\'s stylists with their feed state, and today\'s appointments', function () {
    S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-11-08 14:00:00');
    S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-11-08 15:00:00', ['status' => 'cancelled']);
    S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-11-08 09:00:00', ['status' => 'noShow']);
    S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-11-09 09:00:00');
    S::booked($this->company, $this->york, $this->sam, $this->cut, '2026-11-08 23:30:00');
    $this->actingAs($this->owner)->postJson('/app/salon/feeds', ['shopId' => $this->leeds->id, 'userId' => $this->priya])->assertOk();

    $props = ($this->props)();
    [$leeds, $york] = $props['shops'];

    expect($leeds)->toMatchArray(['name' => 'Leeds', 'todayAppointments' => 1])
        ->and($leeds['stylists'])->toHaveCount(1)
        ->and($leeds['stylists'][0])->toMatchArray(['userId' => $this->priya, 'name' => 'Priya Patel', 'onTill' => true, 'onlineBookable' => true])
        ->and($leeds['stylists'][0]['feed'])->toMatchArray(['on' => true, 'lastFetchedAt' => null])
        ->and($york)->toMatchArray(['name' => 'York', 'todayAppointments' => 1])
        ->and($york['stylists'][0])->toMatchArray(['name' => 'Sam Lee', 'bookable' => false, 'feed' => null])
        ->and($props['stats'])->toBe(['stylists' => 2, 'feedsOn' => 1, 'todayAppointments' => 2, 'pendingRequests' => 0])
        ->and($props['onlineBooking'])->toBeNull()
        ->and(json_encode($props))->not->toContain((string) SalonCalendarFeed::withoutCompanyScope()->value('token_hash'))->not->toContain('/salon/ical/');
});

test('online booking requests are read only, with first names only, a status filter and search', function () {
    S::request($this->company, $this->leeds, ['reference' => 'BK-1001', 'created_at' => '2026-11-07 19:00:00']);
    S::request($this->company, $this->leeds, ['reference' => 'BK-1002', 'client_first_name' => 'Hannah', 'status' => 'accepted', 'decided_at' => '2026-11-07 18:01:00']);
    S::request($this->company, $this->york, ['reference' => 'BK-2001', 'status' => 'declined', 'created_at' => '2026-11-06 10:00:00']);

    $props = ($this->props)();
    expect($props['requests']['meta']['total'])->toBe(3)
        ->and(collect($props['requests']['data'])->pluck('reference')->all())->toBe(['BK-1001', 'BK-1002', 'BK-2001'])
        ->and($props['requests']['data'][1])->toMatchArray(['clientFirstName' => 'Hannah', 'status' => 'accepted', 'shopName' => 'Leeds', 'kind' => 'new', 'decidedAt' => '2026-11-07T18:01:00Z'])
        ->and($props['stats']['pendingRequests'])->toBe(1);

    foreach (['Khan', '07700', 'example.com', 'Long thick hair'] as $forbidden) {
        expect(json_encode($props['requests']))->not->toContain($forbidden);
    }

    expect(collect(($this->props)(null, '?status=declined')['requests']['data'])->pluck('reference')->all())->toBe(['BK-2001'])
        ->and(collect(($this->props)(null, '?search=hannah')['requests']['data'])->pluck('reference')->all())->toBe(['BK-1002']);

    // The page has no route that changes a request: the till decides.
    expect(collect(app('router')->getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'app.salon.'))->values()->all())
        ->toBe(['app.salon.index', 'app.salon.feeds.issue', 'app.salon.feeds.revoke']);
});

test('the top-bar shop narrows the page; a one-shop manager sees and changes only their shop', function () {
    S::request($this->company, $this->york, ['reference' => 'BK-2001']);
    $manager = S::member($this->company, CompanyRole::Manager, $this->leeds);

    $props = ($this->props)($manager);
    expect(collect($props['shops'])->pluck('name')->all())->toBe(['Leeds'])
        ->and($props['requests']['meta']['total'])->toBe(0);

    $this->actingAs($manager)->postJson('/app/salon/feeds', ['shopId' => $this->york->id, 'userId' => $this->sam])->assertUnprocessable()->assertJsonValidationErrors('shopId');
    $yorkFeed = $this->actingAs($this->owner)->postJson('/app/salon/feeds', ['shopId' => $this->york->id, 'userId' => $this->sam])->assertOk()->json('feed.id');
    $this->actingAs($manager)->deleteJson("/app/salon/feeds/{$yorkFeed}")->assertNotFound();
    $this->actingAs($manager)->postJson('/app/salon/feeds', ['shopId' => $this->leeds->id, 'userId' => $this->priya])->assertOk();

    $york = $this->actingAs($this->owner)->withSession([SwitchCurrentBranch::SESSION_KEY => $this->york->id])->get('/app/salon')->assertOk()->viewData('page')['props'];
    expect(collect($york['shops'])->pluck('name')->all())->toBe(['York'])
        ->and(collect($york['requests']['data'])->pluck('reference')->all())->toBe(['BK-2001']);
});

test('a feed is only for a stylist of that salon shop; the reply is never cached', function () {
    $this->actingAs($this->owner)->postJson('/app/salon/feeds', ['shopId' => $this->leeds->id, 'userId' => $this->sam])->assertUnprocessable()->assertJsonValidationErrors('user');
    $this->actingAs($this->owner)->postJson('/app/salon/feeds', ['shopId' => $this->leeds->id])->assertUnprocessable()->assertJsonValidationErrors('userId');
    $this->actingAs($this->owner)->postJson('/app/salon/feeds', ['shopId' => 'nope', 'userId' => $this->priya])->assertUnprocessable()->assertJsonValidationErrors('shopId');

    $response = $this->actingAs($this->owner)->postJson('/app/salon/feeds', ['shopId' => $this->leeds->id, 'userId' => $this->priya])->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->json('regenerated'))->toBeFalse();

    $this->actingAs($this->owner)->deleteJson("/app/salon/feeds/{$response->json('feed.id')}")->assertOk()->assertJsonPath('feed.on', false);
    $this->actingAs($this->owner)->deleteJson("/app/salon/feeds/{$response->json('feed.id')}")->assertOk()->assertJsonPath('changed', false);
});

test('company A cannot see or change company B\'s feeds or requests', function () {
    [$other, $otherShop] = S::business('Fade Barbers');
    $otherOwner = S::member($other, CompanyRole::Owner);
    $otherStylist = S::stylist($other, $otherShop, 'Jay Cole');
    S::request($other, $otherShop, ['reference' => 'BK-B-1', 'client_first_name' => 'Omar']);
    $theirs = $this->actingAs($otherOwner)->postJson('/app/salon/feeds', ['shopId' => $otherShop->id, 'userId' => $otherStylist])->assertOk()->json();

    // A's owner: B's feed is not found, B's shop is not theirs, B's stylist is not in A's shops, B's rows are not on A's page.
    $this->actingAs($this->owner)->deleteJson("/app/salon/feeds/{$theirs['feed']['id']}")->assertNotFound();
    $this->actingAs($this->owner)->postJson('/app/salon/feeds', ['shopId' => $otherShop->id, 'userId' => $otherStylist])->assertUnprocessable();
    $this->actingAs($this->owner)->postJson('/app/salon/feeds', ['shopId' => $this->leeds->id, 'userId' => $otherStylist])->assertUnprocessable();
    $props = ($this->props)();
    expect(json_encode($props))->not->toContain('BK-B-1')->not->toContain('Omar')->not->toContain('Jay Cole')->not->toContain($theirs['feed']['id']);

    // B's feed still works and is unchanged.
    $this->get(S::pathOf($theirs['url']))->assertOk();
    expect(SalonCalendarFeed::withoutCompanyScope()->find($theirs['feed']['id']))->enabled->toBeTrue()->company_id->toBe($other->id);
});

test('admin tenant page: a read-only salon card for a salon business while the switch is on', function () {
    S::stylist($this->company, $this->leeds, 'Tom Reed');
    $this->actingAs($this->owner)->postJson('/app/salon/feeds', ['shopId' => $this->leeds->id, 'userId' => $this->priya])->assertOk();
    S::request($this->company, $this->leeds, ['created_at' => '2026-11-02 09:00:00']);
    S::request($this->company, $this->leeds, ['created_at' => '2026-11-01 23:00:00', 'status' => 'accepted']);
    S::request($this->company, $this->york, ['created_at' => '2026-11-08 08:00:00', 'status' => 'declined']);
    $admin = $this->admin();

    $this->actingAs($admin, 'admin')->get("/admin/tenants/{$this->company->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('salon', [
            'salonShops' => 2, 'stylists' => 3, 'feedsOn' => 1, 'requestsThisWeek' => 2, 'pendingRequests' => 1, 'weekStartsOn' => '2026-11-02',
        ]));

    config(['salon.online' => false]);
    $this->actingAs($admin, 'admin')->get("/admin/tenants/{$this->company->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('salon', null));
});
