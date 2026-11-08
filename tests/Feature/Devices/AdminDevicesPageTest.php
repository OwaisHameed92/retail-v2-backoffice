<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Handheld\Models\HandheldToken;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldTokenSecret;
use App\Domain\Licensing\Actions\ReleaseDevice;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Handheld\HandheldFixtures;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Feature\TillHealth\TillHealthHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, TillHealthHelpers::class);

/** Admin Devices page: every till and handheld of every business. */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00', 'Europe/London'));

    // Khan: till 01 seen an hour ago on 0.1.85, till 02 never activated. Patel: till 01 seen 10 days ago on 0.1.9.
    $this->khan = $this->licensedTenant('Khan Mini Mart', 2, 'LDS');
    $this->patel = $this->licensedTenant('Patel News', 1, 'PNW');
    $this->khanShop = $this->branchOf($this->khan, 'LDS');
    $this->khanTill = $this->registerOf($this->khanShop, '01');
    $this->khanLicence = $this->seen($this->licenceOf($this->khanTill), now()->subHour()->toImmutable(), ['last_app_version' => '0.1.85', 'device_id' => 'INSTALL-KHAN-0001']);
    $this->khanSpare = $this->licenceOf($this->registerOf($this->khanShop, '02'));
    $this->patelTill = $this->registerOf($this->branchOf($this->patel, 'PNW'), '01');
    $this->patelLicence = $this->seen($this->licenceOf($this->patelTill), now()->subDays(10)->toImmutable(), ['last_app_version' => '0.1.9']);

    $this->handheldUser = HandheldFixtures::user($this->khan, $this->khanShop, ['name' => 'Stock room 1']);
    $this->handheld = devicesHandheldToken($this->handheldUser, 'Zebra TC21');
});

function devicesHandheldToken(HandheldUser $user, string $device, bool $revoked = false): HandheldToken
{
    $plain = HandheldTokenSecret::generate();
    $token = HandheldToken::withoutCompanyScope()->create([
        'company_id' => $user->company_id,
        'handheld_user_id' => $user->id,
        'token_hash' => HandheldTokenSecret::hash($plain),
        'token_last4' => HandheldTokenSecret::last4($plain),
        'device_name' => $device,
    ]);
    $token->forceFill(['last_used_at' => now()->subMinutes(5), 'revoked_at' => $revoked ? now() : null])->save();

    return $token;
}

/**
 * The rows of the Devices list for this query string.
 *
 * @param  array<string, mixed>  $query
 * @return list<array<string, mixed>>
 */
function deviceRows(object $test, array $query = []): array
{
    $rows = [];
    $test->actingAs($test->admin(), 'admin')->get(route('admin.devices.index', ['perPage' => 100, ...$query]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$rows) {
            $rows = $page->toArray()['props']['devices']['data'];
        });

    return $rows;
}

/**
 * The sorted ids of the Devices list for this query string.
 *
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function deviceIds(object $test, array $query = []): array
{
    return collect(deviceRows($test, $query))->pluck('id')->sort()->values()->all();
}

test('guests, customers and deactivated admins cannot open the page', function () {
    $this->get('/admin/devices')->assertRedirect(route('admin.login'));
    $this->getJson('/admin/devices')->assertUnauthorized();

    $this->actingAs($this->ownerOf($this->khan))->get('/admin/devices')->assertRedirect(route('admin.login'));

    $admin = $this->admin(AdminRole::Support);
    $admin->update(['is_active' => false]);
    $this->actingAs($admin, 'admin')->get('/admin/devices')->assertRedirect(route('admin.login'));
});

test('the page sits behind auth:admin and can:tenants.view (every role has it today), like its nav item', function () {
    $route = Route::getRoutes()->getByName('admin.devices.index');
    expect($route->gatherMiddleware())->toContain('can:'.AdminRole::TENANTS_VIEW)
        ->and($route->gatherMiddleware())->toContain('auth:admin');

    $nav = file_get_contents(resource_path('js/components/admin/admin-nav.ts'));
    expect(preg_match("/title: 'Devices',[^}]*route: 'admin\\.devices\\.index',[^}]*ability: 'tenants\\.view'/s", $nav))->toBe(1);
});

test('every admin role sees the tills of every business and the handhelds', function (AdminRole $role) {
    $this->actingAs($this->admin($role), 'admin')->get('/admin/devices')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/devices/index')
            ->has('devices.data', 4)
            ->where('devices.meta.total', 4)
            ->where('summary.tills', 3)
            ->where('summary.liveToday', 1)
            ->where('summary.notSeen', 1)
            ->where('summary.oldVersion', 1)
            ->where('summary.handhelds', 1)
            ->where('can.release', $role->can(AdminRole::LICENCES_MANAGE)));
})->with([AdminRole::Owner, AdminRole::Support, AdminRole::Sales, AdminRole::Accounts]);

test('a till row has its business, shop, till, version, last seen, status and install id', function () {
    $this->actingAs($this->admin(), 'admin')->get('/admin/devices?search=INSTALL-KHAN')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('devices.data', 1)
            ->where('devices.data.0.kind', 'till')
            ->where('devices.data.0.company.name', 'Khan Mini Mart')
            ->where('devices.data.0.branch.code', 'LDS')
            ->where('devices.data.0.code', '01')
            ->where('devices.data.0.isMainTill', true)
            ->where('devices.data.0.appVersion', '0.1.85')
            ->where('devices.data.0.appOutdated', false)
            ->where('devices.data.0.lastSeenAt', now()->subHour()->utc()->format('Y-m-d\TH:i:s\Z'))
            ->where('devices.data.0.status', 'active')
            ->where('devices.data.0.licenceId', $this->khanLicence->id)
            ->where('devices.data.0.installId', 'INSTALL-KHAN-0001')
            ->where('devices.data.0.keyLast4', $this->khanLicence->key_last4)
            ->where('devices.data.0.clockSkewSeconds', 2)
            ->where('devices.data.0.canRelease', true));
});

test('a handheld row has its business, shop, user, device and status', function () {
    $this->actingAs($this->admin(), 'admin')->get('/admin/devices?kind=handheld')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('devices.data', 1)
            ->where('devices.data.0.id', $this->handheld->id)
            ->where('devices.data.0.kind', 'handheld')
            ->where('devices.data.0.company.name', 'Khan Mini Mart')
            ->where('devices.data.0.branch.id', $this->khanShop->id)
            ->where('devices.data.0.name', 'Stock room 1')
            ->where('devices.data.0.deviceName', 'Zebra TC21')
            ->where('devices.data.0.appVersion', null)
            ->where('devices.data.0.status', 'signedIn')
            ->where('devices.data.0.licenceId', null)
            ->where('devices.data.0.canRelease', false));
});

test('kind, search, not seen and status filters narrow the list', function () {
    expect(deviceIds($this, ['kind' => 'till']))->toBe(collect([$this->khanLicence->id, $this->khanSpare->id, $this->patelLicence->id])->sort()->values()->all())
        ->and(deviceIds($this, ['kind' => 'handheld']))->toBe([$this->handheld->id])
        ->and(deviceIds($this, ['search' => 'Patel']))->toBe([$this->patelLicence->id])
        ->and(deviceIds($this, ['search' => 'Zebra']))->toBe([$this->handheld->id])
        ->and(deviceIds($this, ['search' => $this->patelLicence->key_last4]))->toContain($this->patelLicence->id)
        ->and(deviceIds($this, ['unseen' => 1]))->toBe([$this->patelLicence->id])
        ->and(deviceIds($this, ['unseen' => 1, 'days' => 14]))->toBe([])
        ->and(deviceIds($this, ['status' => 'active']))->toBe(collect([$this->khanLicence->id, $this->patelLicence->id])->sort()->values()->all())
        ->and(deviceIds($this, ['status' => 'notActivated']))->toBe([$this->khanSpare->id])
        ->and(deviceIds($this, ['status' => 'signedIn']))->toBe([$this->handheld->id])
        ->and(deviceIds($this, ['status' => 'signedOut']))->toBe([]);

    // A status of the other kind is dropped; unknown values are ignored, never an error.
    $this->actingAs($this->admin(), 'admin')->get('/admin/devices?kind=till&status=signedIn&days=zzz&below=not-a-version')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('devices.data', 3)
        ->where('filters.status', null)->where('filters.days', 7)->where('filters.below', null));
    expect(deviceIds($this, ['kind' => 'nope', 'status' => 'nope']))->toHaveCount(4);
});

test('expiring, released, suspended, revoked and signed-out devices', function () {
    $this->khanLicence->forceFill(['expires_at' => now()->addDays(3)])->save();
    app(ReleaseDevice::class)->handle($this->patelLicence->refresh());
    $spare = $this->seen($this->khanSpare, now()->subHours(2)->toImmutable());
    app(SuspendLicence::class)->handle($spare, 'Unpaid invoice');
    $signedOut = devicesHandheldToken($this->handheldUser, 'Old Zebra', revoked: true);

    expect(deviceIds($this, ['status' => 'expiring']))->toBe([$this->khanLicence->id])
        ->and(deviceIds($this, ['status' => 'released']))->toBe([$this->patelLicence->id])
        ->and(deviceIds($this, ['status' => 'suspended']))->toBe([$spare->id])
        ->and(deviceIds($this, ['status' => 'active']))->toBe([])
        ->and(deviceIds($this, ['status' => 'signedOut']))->toBe([$signedOut->id]);

    // A revoked licence leaves the default list and shows under "Revoked" only.
    app(RevokeLicence::class)->handle($this->patelLicence->refresh(), 'Shop closed');
    expect(deviceIds($this))->not->toContain($this->patelLicence->id)
        ->and(deviceIds($this, ['status' => 'revoked']))->toBe([$this->patelLicence->id]);

    $this->actingAs($this->admin(), 'admin')->get('/admin/devices?status=revoked')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('devices.data.0.status', 'revoked')->where('devices.data.0.canRelease', false));
});

test('each row\'s status matches the SQL filter for that status', function () {
    $this->khanLicence->forceFill(['expires_at' => now()->addDays(3)])->save();
    app(ReleaseDevice::class)->handle($this->patelLicence->refresh());
    devicesHandheldToken($this->handheldUser, 'Old Zebra', revoked: true);

    $seen = [];
    foreach (DeviceStatus::cases() as $status) {
        foreach (deviceRows($this, ['status' => $status->value]) as $row) {
            expect($row['status'])->toBe($status->value);
            $seen[] = $row['id'];
        }
    }

    // Every listed device turns up under its own status.
    expect(collect(deviceRows($this))->pluck('id')->diff($seen)->all())->toBe([])
        ->and(count($seen))->toBe(5);
});

test('old EPOS version compares versions as semver: 0.1.9 is older than 0.1.85', function () {
    $this->actingAs($this->admin(), 'admin')->get('/admin/devices?old=1')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('devices.data', 1)
            ->where('devices.data.0.id', $this->patelLicence->id)
            ->where('devices.data.0.appOutdated', true)
            ->where('filters.gate', '0.1.85')
            ->where('summary.gate', '0.1.85')
            ->where('versions', [['version' => '0.1.85', 'tills' => 1], ['version' => '0.1.9', 'tills' => 1]]));

    expect(deviceIds($this, ['old' => 1, 'below' => '0.1.9']))->toBe([])
        ->and(deviceIds($this, ['old' => 1, 'below' => '0.1.10']))->toBe([$this->patelLicence->id])
        ->and(deviceIds($this, ['old' => 1, 'below' => '0.2.0']))->toBe(collect([$this->khanLicence->id, $this->patelLicence->id])->sort()->values()->all())
        // Handhelds report no version, so the filter lists tills only.
        ->and(deviceIds($this, ['old' => 1, 'kind' => 'handheld']))->toBe([]);
});

test('no key, token or hash reaches the page', function () {
    $licence = Licence::withoutCompanyScope()->findOrFail($this->khanLicence->id);
    $licence->forceFill(['token_sha256' => hash('sha256', 'a-token'), 'token_fingerprint' => 'fp-'.Ulid::new()])->save();
    $tokenHash = HandheldToken::withoutCompanyScope()->whereKey($this->handheld->id)->value('token_hash');
    $tokenLast4 = HandheldToken::withoutCompanyScope()->whereKey($this->handheld->id)->value('token_last4');

    $response = $this->actingAs($this->admin(), 'admin')->get('/admin/devices?perPage=100')->assertOk();
    $page = $response->viewData('page');
    $props = json_encode([$page['props']['devices'], $page['props']['summary'], $page['props']['filters']]);

    expect($props)->not->toContain($licence->key_hash)
        ->and($props)->not->toContain((string) $licence->token_sha256)
        ->and($props)->not->toContain((string) $licence->token_fingerprint)
        ->and($props)->not->toContain($tokenHash)
        ->and($props)->not->toMatch('/"'.preg_quote($tokenLast4, '/').'"/')
        ->and($props)->not->toMatch('/key_?hash|token_?(sha256|hash|fingerprint)|password/i');

    // A full licence key is never searched or echoed from a URL.
    $this->actingAs($this->admin(), 'admin')->get('/admin/devices?search='.LicenceKey::generate()->formatted())
        ->assertRedirect(route('admin.devices.index'))->assertSessionHas('error');
});

test('the page renders with no devices', function () {
    Licence::withoutCompanyScope()->forceDelete();
    HandheldToken::withoutCompanyScope()->delete();

    $this->actingAs($this->admin(), 'admin')->get('/admin/devices')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/devices/index')
            ->has('devices.data', 0)
            ->where('summary.tills', 0)
            ->where('summary.handhelds', 0)
            ->where('summary.gate', null)
            ->where('versions', []));
});

test('the page changes nothing', function () {
    $before = Licence::withoutCompanyScope()->orderBy('id')->get()->map->getAttributes()->all();

    $this->actingAs($this->admin(), 'admin')->get('/admin/devices?old=1&unseen=1&status=active')->assertOk();

    expect(Licence::withoutCompanyScope()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
});

test('last seen is the later of the licence check-in and the shop\'s last sync (till health)', function () {
    $this->refreshHealth();
    DB::table('till_health')->where('licence_id', $this->patelLicence->id)->update(['last_seen_at' => now()->subMinutes(10)->utc()->format('Y-m-d H:i:s')]);

    expect(deviceRows($this, ['search' => 'Patel'])[0]['lastSeenAt'])->toBe(now()->subMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and(deviceRows($this, ['search' => 'INSTALL-KHAN'])[0]['lastSeenAt'])->toBe(now()->subHour()->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and(deviceIds($this, ['unseen' => 1]))->toBe([]);
});
