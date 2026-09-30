<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Module 5.6: staff time pages (clock events, timesheets, payroll CSV, rota). Who may see them (staff.view: owner,
 * manager, accountant), a one-shop manager's own shop and staff only, one business never seeing another's, and the
 * figures built from the tills' rows. Week of Monday 21 Sep 2026, "now" Thursday 24th noon, London.
 */

const ALI = '01K5T0Q8C40000000000USRALI';
const BEA = '01K5T0Q8C40000000000USRBEA';
const TIME_PAGES = ['/app/staff/time', '/app/staff/time/timesheets', '/app/staff/time/rota', '/app/staff/time/timesheets/export'];

function tillUser(string $companyId, string $id, string $name, ?string $rate, ?string $maxHours = null): void
{
    DB::table('till_users')->insert(['id' => $id, 'company_id' => $companyId, 'name' => $name, 'rate_per_hour' => $rate, 'max_shift_hours' => $maxHours, 'is_active' => true]);
}

/** Clock events at London times: [type, "Y-m-d H:i"]. */
function clocks(string $companyId, string $userId, string $shop, string $till, array $events): void
{
    foreach ($events as $i => [$type, $london]) {
        DB::table('clock_events')->insert([
            'id' => substr($userId, -3).substr($shop, -3).str_pad((string) $i, 3, '0', STR_PAD_LEFT).substr(md5($london.$type.$companyId), 0, 17),
            'company_id' => $companyId, 'branch_id' => $shop, 'register_id' => $till, 'user_id' => $userId, 'type' => $type, 'note' => '',
            'at' => CarbonImmutable::parse($london, 'Europe/London')->utc()->format('Y-m-d H:i:s'),
        ]);
    }
}

function rotaShift(string $companyId, string $id, string $userId, string $shop, string $day, string $start, string $end, int $break, bool $published = true): void
{
    DB::table('rota_shifts')->insert([
        'id' => $id, 'company_id' => $companyId, 'branch_id' => $shop, 'user_id' => $userId, 'shift_date' => $day, 'start_time' => $start,
        'end_time' => $end, 'break_minutes' => $break, 'is_published' => $published, 'note' => '',
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00', 'Europe/London'));
    [$this->company] = T::tenant();
    $id = $this->company->id;

    tillUser($id, ALI, 'Ali Khan', '12.00', '8');
    tillUser($id, BEA, '=Bea Jones', null);
    DB::table('till_user_branches')->insert(['id' => '01K5T0Q8C40000000000SBBEA1', 'company_id' => $id, 'till_user_id' => BEA, 'branch_id' => T::BRADFORD]);

    clocks($id, ALI, T::LEEDS, T::TILL_1, [
        ['in', '2026-09-21 09:00'], ['breakStart', '2026-09-21 12:00'], ['breakEnd', '2026-09-21 12:30'], ['out', '2026-09-21 17:00'],
        ['in', '2026-09-22 22:00'], ['out', '2026-09-23 06:00'],
        ['in', '2026-09-23 14:00'],
        ['in', '2026-09-24 09:00'], ['out', '2026-09-24 11:00'],
    ]);
    clocks($id, BEA, T::BRADFORD, T::BRADFORD_TILL, [['in', '2026-09-21 08:00'], ['out', '2026-09-21 16:00']]);

    rotaShift($id, '01K5T0Q8C40000000000ROTA01', ALI, T::LEEDS, '2026-09-21', '09:00:00', '17:00:00', 30);
    rotaShift($id, '01K5T0Q8C40000000000ROTA02', ALI, T::LEEDS, '2026-09-25', '09:00', '13:00', 0, false);
    rotaShift($id, '01K5T0Q8C40000000000ROTA03', BEA, T::BRADFORD, '2026-09-21', '08:00', '16:00', 0);

    DB::table('timesheet_approvals')->insert([
        'id' => '01K5T0Q8C40000000000APPR01', 'company_id' => $id, 'branch_id' => T::LEEDS, 'user_id' => ALI, 'week_start' => '2026-09-21',
        'approved_by_user_id' => BEA, 'approved_at' => '2026-09-24 10:00:00', 'total_hours' => '17.5000', 'overtime_hours' => '0.0000',
    ]);

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $shop = Branch::factory()->forCompany($this->other)->create(['name' => 'Other shop']);
    $till = Register::factory()->forBranch($shop)->create();
    tillUser($this->other->id, '01K5T0Q8C40000000000USROTH', 'Other Person', '20.00');
    clocks($this->other->id, '01K5T0Q8C40000000000USROTH', $shop->id, $till->id, [['in', '2026-09-21 06:00'], ['out', '2026-09-21 20:00']]);
    rotaShift($this->other->id, '01K5T0Q8C40000000000ROTAOT', '01K5T0Q8C40000000000USROTH', $shop->id, '2026-09-22', '06:00', '20:00', 0);
});

test('guests are sent to log in and portal staff get 403 on every staff time page', function () {
    foreach (TIME_PAGES as $url) {
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(C::member($this->company, CompanyRole::Staff))->get($url)->assertForbidden();
        auth()->logout();
    }
});

test('owners, managers and accountants may see staff time; staff may not', function () {
    foreach ([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant] as $role) {
        $user = C::member($this->company, $role);
        foreach (TIME_PAGES as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
        expect($role->can('staff.view'))->toBeTrue();
    }

    expect(CompanyRole::Staff->can('staff.view'))->toBeFalse();
});

test('the rota is read only: rota rows are the tills\' (branch-owned), so there is no write route', function () {
    $owner = C::member($this->company, CompanyRole::Owner);

    foreach (['post', 'put', 'delete'] as $method) {
        $this->actingAs($owner)->{$method}('/app/staff/time/rota')->assertStatus(405);
    }

    expect(json_decode((string) file_get_contents(base_path(T::CONTRACT.'/samples/ownership.json')), true)['entities']['RotaShift'] ?? null)->toBe('branch');
});

test('clock events pair into shifts with the missing clock-out flagged and not counted', function () {
    $props = C::props($this->actingAs(C::member($this->company, CompanyRole::Owner))->get('/app/staff/time?shop=all'));

    expect($props['summary'])->toMatchArray(['shifts' => 4, 'workedMinutes' => 450 + 480 + 120 + 480, 'missing' => 1, 'onShift' => 0, 'problems' => 1])
        ->and($props['filters'])->toMatchArray(['from' => '2026-09-21', 'to' => '2026-09-27', 'shop' => null]);

    $rows = collect($props['shifts']['data']);
    $night = $rows->firstWhere('clockIn', '2026-09-22T21:00:00Z');
    expect($night)->toMatchArray(['person' => 'Ali Khan', 'shop' => 'Leeds', 'till' => 'Till 1', 'day' => '2026-09-22', 'workedMinutes' => 480, 'status' => 'complete'])
        ->and($rows->firstWhere('day', '2026-09-21')['planned'] ?? null)->not->toBeNull();

    $problems = C::props($this->actingAs(C::member($this->company, CompanyRole::Owner))->get('/app/staff/time?shop=all&problems=1'));
    expect($problems['shifts']['data'])->toHaveCount(1)
        ->and($problems['shifts']['data'][0])->toMatchArray(['status' => 'missingOut', 'day' => '2026-09-23', 'workedMinutes' => 0]);
});

test('a shift longer than the person\'s max shift hours is flagged', function () {
    clocks($this->company->id, ALI, T::LEEDS, T::TILL_1, [['in', '2026-09-26 06:00'], ['out', '2026-09-26 16:00']]);

    $rows = collect(C::props($this->actingAs(C::member($this->company, CompanyRole::Owner))->get('/app/staff/time?shop=all'))['shifts']['data']);

    expect($rows->firstWhere('day', '2026-09-26')['flags'])->toBe(['overMaxShift']);
});

test('timesheets add up per person, shop and week with rota hours, till approval, overtime and wage estimate', function () {
    $owner = C::member($this->company, CompanyRole::Owner);
    $props = C::props($this->actingAs($owner)->get('/app/staff/time/timesheets?shop=all&overtime=16'));
    $ali = collect($props['timesheets']['data'])->firstWhere('personId', ALI);
    $bea = collect($props['timesheets']['data'])->firstWhere('personId', BEA);

    expect($ali)->toMatchArray([
        'shop' => 'Leeds', 'weekStart' => '2026-09-21', 'shifts' => 3, 'workedMinutes' => 1050, 'paidMinutes' => 1050, 'breakMinutes' => 30,
        'overtimeMinutes' => 90, 'plannedMinutes' => 690, 'differenceMinutes' => 360, 'missing' => 1, 'rate' => '12.00', 'wage' => '210.00',
    ])->and($ali['approval'])->toMatchArray(['approvedBy' => '=Bea Jones', 'totalHours' => '17.50'])
        ->and($bea)->toMatchArray(['shop' => 'Bradford', 'paidMinutes' => 480, 'plannedMinutes' => 480, 'rate' => null, 'wage' => null, 'approval' => null])
        ->and($props['summary'])->toMatchArray(['people' => 2, 'paidMinutes' => 1530, 'wages' => '210.00', 'withoutRate' => 1, 'missing' => 1]);

    $rounded = collect(C::props($this->actingAs($owner)->get('/app/staff/time/timesheets?shop=all&rounding=15&group=period'))['timesheets']['data']);
    expect($rounded->firstWhere('personId', ALI))->toMatchArray(['weekStart' => null, 'paidMinutes' => 1050, 'approval' => null]);
});

test('the payroll CSV has one line per timesheet row, plain decimals, formula-safe names', function () {
    $response = $this->actingAs(C::member($this->company, CompanyRole::Accountant))->get('/app/staff/time/timesheets/export?shop=all&overtime=16');
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $csv = $response->streamedContent();

    expect($csv)->toContain('Staff,"Staff id",Shop,"Week starting"')
        ->toContain('"Ali Khan",'.ALI.',Leeds,2026-09-21,3,17.50,17.50,0.50,1.50,11.50,12.00,210.00,1,17.50,0.00,2026-09-24')
        ->toContain("\"'=Bea Jones\",".BEA.',Bradford,2026-09-21,1,8.00,8.00,0.00,0.00,8.00,,,0,')
        ->toContain('After 16 hours a week')
        ->not->toContain('Other Person');
});

test('the rota week shows planned beside worked, with drafts counted', function () {
    $props = C::props($this->actingAs(C::member($this->company, CompanyRole::Manager))->get('/app/staff/time/rota?shop=all&week=2026-09-23'));
    $ali = collect($props['rows'])->firstWhere('personId', ALI);

    expect($props['week'])->toBe('2026-09-21')
        ->and($props['days'])->toHaveCount(7)
        ->and($props['summary'])->toMatchArray(['people' => 2, 'shifts' => 3, 'drafts' => 1, 'plannedMinutes' => 1170, 'workedMinutes' => 1530])
        ->and($ali)->toMatchArray(['plannedMinutes' => 690, 'workedMinutes' => 1050])
        ->and($ali['days']['2026-09-21']['planned'][0])->toMatchArray(['start' => '09:00', 'end' => '17:00', 'minutes' => 450, 'isPublished' => true])
        ->and($ali['days']['2026-09-23'])->toMatchArray(['missing' => 1, 'workedMinutes' => 0])
        ->and($ali['days']['2026-09-27'])->toBeNull();
});

test('a one-shop manager sees only their shop\'s staff and hours, whatever shop they ask for', function () {
    $manager = C::member($this->company, CompanyRole::Manager, T::BRADFORD);

    foreach (['/app/staff/time?shop='.T::LEEDS, '/app/staff/time/timesheets?shop=all', '/app/staff/time/rota?shop=all'] as $url) {
        $props = C::props($this->actingAs($manager)->get($url));
        expect($props['filters'])->toMatchArray(['shop' => T::BRADFORD, 'shopLocked' => true])
            ->and(array_column($props['options']['people'], 'value'))->toBe([BEA])
            ->and(array_column($props['options']['shops'], 'label'))->toBe(['Bradford']);
    }

    expect(array_column(C::props($this->actingAs($manager)->get('/app/staff/time?shop=all'))['shifts']['data'], 'personId'))->toBe([BEA])
        ->and(array_column(C::props($this->actingAs($manager)->get('/app/staff/time/timesheets?person='.ALI))['timesheets']['data'], 'personId'))->toBe([])
        ->and(array_column(C::props($this->actingAs($manager)->get('/app/staff/time/rota'))['rows'], 'personId'))->toBe([BEA]);

    $csv = $this->actingAs($manager)->get('/app/staff/time/timesheets/export?shop='.T::LEEDS)->streamedContent();
    expect($csv)->toContain('Bradford')->not->toContain('Ali Khan');
});

test('one business never sees another\'s clock events, timesheets, rota or staff', function () {
    $owner = C::member($this->company, CompanyRole::Owner);
    $clock = C::props($this->actingAs($owner)->get('/app/staff/time?shop=all'));
    $sheets = C::props($this->actingAs($owner)->get('/app/staff/time/timesheets?shop=all&person=01K5T0Q8C40000000000USROTH'));
    $rota = C::props($this->actingAs($owner)->get('/app/staff/time/rota?shop=all'));

    expect(array_column($clock['shifts']['data'], 'person'))->not->toContain('Other Person')
        ->and(array_column($clock['options']['people'], 'label'))->toBe(['=Bea Jones', 'Ali Khan'])
        ->and($sheets['timesheets']['data'])->toBe([])
        ->and(array_column($rota['rows'], 'person'))->not->toContain('Other Person');

    $other = C::props($this->actingAs(C::member($this->other, CompanyRole::Owner))->get('/app/staff/time/timesheets?shop=all'));
    expect(array_column($other['timesheets']['data'], 'person'))->toBe(['Other Person']);
});
