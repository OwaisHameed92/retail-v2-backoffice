<?php

use App\Domain\Calendar\Models\ShopOpeningHour;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Module 5.9: opening hours (portal-kept, sent to the tills as `shop.trading_hours`), the tills' special days and
 * seasonal events (read only) and "compare with last year's event" from rpt_* rows. "Now" is 30 Sept 2026.
 */

function calendarWeek(string $opens = '07:00', string $closes = '22:00', bool $sundayClosed = true): array
{
    $days = [];
    foreach (range(1, 7) as $day) {
        $days[] = $day === 7 && $sundayClosed ? ['closed' => true] : ['closed' => false, 'opens' => $opens, 'closes' => $closes];
    }

    return $days;
}

function rptDay(Company $company, Branch $shop, string $day, string $gross, string $net, int $txn): void
{
    DB::table('rpt_sales_daily')->insert([
        'company_id' => $company->id, 'branch_id' => $shop->id, 'trading_day' => $day, 'register_id' => '',
        'gross' => $gross, 'net' => $net, 'txn_count' => $txn, 'takings' => $gross, 'rebuilt_at' => '2026-09-30 00:00:00',
    ]);
}

function seasonalEvent(Company $company, Branch $shop, string $name, string $starts, string $ends, string $kind = 'moveable'): string
{
    return F::row('seasonal_events', $company, $shop, [
        'name' => $name, 'kind' => $kind, 'starts_on' => $starts, 'ends_on' => $ends, 'nation' => 'England', 'notes' => '', 'is_active' => true, 'days' => 1,
    ]);
}

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo('2026-09-30 10:00:00');
    $this->sync = new SyncApiFixtures($this);
    [$this->company, $this->leeds, $this->bradford] = [$this->sync->company, $this->sync->leeds, $this->sync->bradford];
    $this->owner = F::member($this->company, CompanyRole::Owner);
    $this->props = fn (string $url, $user = null) => $this->actingAs($user ?? $this->owner)->get($url)->assertOk()->viewData('page')['props'];
    $this->tradingHours = fn (bool $bradford = false) => collect(Pull::changes($this->sync->pull(0, bradford: $bradford)))
        ->where('entity', 'Setting')->where('payload.key', 'shop.trading_hours')->values();

    $this->christmas = seasonalEvent($this->company, $this->leeds, 'Christmas', '2026-12-20', '2026-12-26', 'fixed');
    $this->eid = seasonalEvent($this->company, $this->leeds, 'Eid al-Adha', '2026-05-26', '2026-05-28');
    $this->eidLastYear = seasonalEvent($this->company, $this->leeds, 'eid al-adha', '2025-06-06', '2025-06-08');
    $this->easter = seasonalEvent($this->company, $this->bradford, 'Easter', '2026-04-03', '2026-04-06');
    $this->harvest = seasonalEvent($this->company, $this->leeds, 'Harvest week', '2026-09-28', '2026-10-04', 'local');
    F::row('branch_hours_overrides', $this->company, $this->leeds, [
        'date' => '2026-12-25', 'seasonal_event_id' => $this->christmas, 'seasonal_event_name' => 'Christmas', 'is_closed' => true, 'notes' => 'Closed all day',
    ]);
    F::row('branch_hours_overrides', $this->company, $this->bradford, [
        'date' => '2026-12-24', 'seasonal_event_id' => '', 'seasonal_event_name' => '', 'is_closed' => false,
        'general_opens_at' => '08:00:00', 'general_closes_at' => '14:00:00', 'notes' => '',
    ]);
    F::row('branch_hours_overrides', $this->company, $this->leeds, ['date' => '2026-08-31', 'seasonal_event_id' => '', 'seasonal_event_name' => 'Bank holiday', 'is_closed' => true, 'notes' => '']);
});

test('guests go to the login page; staff and accountants get 403; owners and managers may look', function () {
    $urls = ['/app/calendar', '/app/calendar/special-days', '/app/calendar/events', "/app/calendar/events/{$this->eid}"];
    foreach ($urls as $url) {
        $this->get($url)->assertRedirect('/login');
    }
    foreach ([CompanyRole::Staff, CompanyRole::Accountant] as $role) {
        $user = F::member($this->company, $role);
        foreach ($urls as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
        $this->actingAs($user)->put("/app/calendar/hours/{$this->leeds->id}", ['days' => calendarWeek()])->assertForbidden();
    }
    $manager = F::member($this->company, CompanyRole::Manager);
    $this->actingAs($manager)->get('/app/calendar')->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/calendar/hours'));
    $this->actingAs($manager)->get('/app/calendar/events')->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/calendar/events'));
    $this->actingAs($manager)->get('/app/calendar/special-days')->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/calendar/special-days'));
    $this->actingAs($manager)->get("/app/calendar/events/{$this->eid}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/calendar/event'));
});

test('saving a shop\'s week sends it to that shop\'s tills as shop.trading_hours in the pull; clearing sends a D', function () {
    $this->actingAs($this->owner)->put("/app/calendar/hours/{$this->leeds->id}", ['days' => calendarWeek()])->assertRedirect()->assertSessionHas('success');

    $sent = ($this->tradingHours)()->sole();
    expect($sent['payload']['value'])->toBe("Mon 07:00-22:00\nTue 07:00-22:00\nWed 07:00-22:00\nThu 07:00-22:00\nFri 07:00-22:00\nSat 07:00-22:00\nSun Closed")
        ->and($sent['payload']['scope'])->toBe('branch')
        ->and(($this->tradingHours)(true))->toHaveCount(0)
        ->and(ShopOpeningHour::withoutCompanyScope()->where('branch_id', $this->leeds->id)->count())->toBe(7)
        ->and(AuditLog::query()->where('action', 'opening_hours.updated')->count())->toBe(1);

    $page = ($this->props)('/app/calendar');
    $leeds = collect($page['shops'])->firstWhere('id', $this->leeds->id);
    expect($leeds['tillTextMatches'])->toBeTrue()->and($leeds['days'][6])->toMatchArray(['name' => 'Sun', 'closed' => true])
        ->and($leeds['specialDays'][0])->toMatchArray(['date' => '2026-12-25', 'closed' => true, 'event' => 'Christmas'])
        ->and(collect($page['shops'])->firstWhere('id', $this->bradford->id)['days'])->toBeNull();

    // The same week again changes nothing; clearing removes the setting.
    $this->actingAs($this->owner)->put("/app/calendar/hours/{$this->leeds->id}", ['days' => calendarWeek()])->assertSessionHas('success', 'Nothing changed.');
    $this->actingAs($this->owner)->put("/app/calendar/hours/{$this->leeds->id}", ['clear' => true])->assertSessionHas('success');
    expect(($this->tradingHours)()->sole()['op'])->toBe('D')
        ->and(ShopOpeningHour::withoutCompanyScope()->count())->toBe(0);

    // Every shop at once.
    $this->actingAs($this->owner)->put("/app/calendar/hours/{$this->leeds->id}", ['days' => calendarWeek('06:00', '23:30', false), 'everyShop' => true]);
    expect(($this->tradingHours)(true)->sole()['payload']['value'])->toEndWith('Sun 06:00-23:30')
        ->and(ShopOpeningHour::withoutCompanyScope()->count())->toBe(14);
});

test('bad times are refused with a message per day', function () {
    $days = calendarWeek();
    $days[0]['opens'] = '7am';
    $days[2]['closes'] = '25:00';
    $this->actingAs($this->owner)->from('/app/calendar')->put("/app/calendar/hours/{$this->leeds->id}", ['days' => $days])
        ->assertRedirect('/app/calendar')->assertSessionHasErrors(['days.1.opens', 'days.3.closes']);
    $this->actingAs($this->owner)->put("/app/calendar/hours/{$this->leeds->id}", ['days' => array_slice($days, 0, 3)])->assertSessionHasErrors('days');
    expect(ShopOpeningHour::withoutCompanyScope()->count())->toBe(0);
});

test('special days and events are listed from the tills, upcoming first; past and every-shop filters work', function () {
    $special = ($this->props)('/app/calendar/special-days?shop=all');
    expect(collect($special['days'])->pluck('date')->all())->toBe(['2026-12-24', '2026-12-25'])
        ->and($special['days'][0])->toMatchArray(['shop' => 'Bradford', 'closed' => false, 'opens' => '08:00', 'closes' => '14:00']);
    expect(collect(($this->props)('/app/calendar/special-days?shop=all&when=past')['days'])->pluck('event')->all())->toBe(['Bank holiday']);

    $events = ($this->props)('/app/calendar/events?shop=all');
    expect(collect($events['events'])->pluck('status', 'name')->all())->toBe(['Harvest week' => 'onNow', 'Christmas' => 'upcoming']);
    expect(collect(($this->props)('/app/calendar/events?shop=all&when=past')['events'])->pluck('name')->all())->toBe(['Eid al-Adha', 'Easter', 'eid al-adha']);
    expect(collect(($this->props)("/app/calendar/events?shop={$this->bradford->id}&when=all")['events'])->pluck('name')->all())->toBe(['Easter']);
});

test('an event is compared with last year\'s event of the same name, else the same dates; figures come from rpt rows', function () {
    rptDay($this->company, $this->leeds, '2026-05-26', '120.00', '100.00', 10);
    rptDay($this->company, $this->leeds, '2026-05-27', '240.00', '200.00', 20);
    rptDay($this->company, $this->bradford, '2026-05-26', '999.00', '800.00', 50);
    rptDay($this->company, $this->leeds, '2025-06-06', '60.00', '50.00', 5);
    rptDay($this->company, $this->leeds, '2025-06-07', '60.00', '50.00', 5);
    rptDay($this->company, $this->leeds, '2025-05-26', '5000.00', '4000.00', 400); // same dates last year: not used

    $eid = ($this->props)("/app/calendar/events/{$this->eid}");
    expect($eid['basis'])->toBe('lastYearEvent')
        ->and($eid['lastYear'])->toMatchArray(['from' => '2025-06-06', 'to' => '2025-06-08'])
        ->and($eid['totals']['gross'])->toBe(['current' => '360.00', 'previous' => '120.00', 'change' => '200.0'])
        ->and($eid['totals']['transactions'])->toBe(['current' => 30, 'previous' => 10, 'change' => '200.0'])
        ->and(collect($eid['days'])->pluck('gross')->all())->toBe(['120.00', '240.00', '0.00'])
        ->and(collect($eid['days'])->pluck('lastYearGross')->all())->toBe(['60.00', '60.00', '0.00'])
        ->and($eid['canCompareEveryShop'])->toBeTrue();
    expect(($this->props)("/app/calendar/events/{$this->eid}?shops=all")['totals']['gross']['current'])->toBe('1359.00');

    $easter = ($this->props)("/app/calendar/events/{$this->easter}");
    expect($easter['basis'])->toBe('sameDates')->and($easter['lastYear'])->toMatchArray(['from' => '2025-04-03', 'to' => '2025-04-06']);

    // On now: compared day for day so far (28–30 Sept).
    $harvest = ($this->props)("/app/calendar/events/{$this->harvest}");
    expect($harvest['daysCompared'])->toBe(3)->and($harvest['thisYear'])->toBe(['from' => '2026-09-28', 'to' => '2026-09-30']);
    // Upcoming: last year only.
    expect(collect(($this->props)("/app/calendar/events/{$this->christmas}")['days'])->pluck('gross')->unique()->all())->toBe([null]);
});

test('a one-shop manager sees and changes only their shop', function () {
    $manager = F::member($this->company, CompanyRole::Manager, $this->leeds);

    expect(collect(($this->props)('/app/calendar', $manager)['shops'])->pluck('id')->all())->toBe([$this->leeds->id])
        ->and(collect(($this->props)("/app/calendar/events?shop={$this->bradford->id}&when=all", $manager)['events'])->pluck('name')->unique()->sort()->values()->all())
        ->toBe(['Christmas', 'Eid al-Adha', 'Harvest week', 'eid al-adha'])
        ->and(collect(($this->props)('/app/calendar/special-days?shop=all', $manager)['days'])->pluck('date')->all())->toBe(['2026-12-25']);

    $this->actingAs($manager)->get("/app/calendar/events/{$this->easter}")->assertNotFound();
    expect(($this->props)("/app/calendar/events/{$this->eid}?shops=all", $manager)['everyShop'])->toBeFalse();

    $this->actingAs($manager)->put("/app/calendar/hours/{$this->bradford->id}", ['days' => calendarWeek()])->assertForbidden();
    $this->actingAs($manager)->put("/app/calendar/hours/{$this->leeds->id}", ['days' => calendarWeek(), 'everyShop' => true])->assertForbidden();
    $this->actingAs($manager)->put("/app/calendar/hours/{$this->leeds->id}", ['days' => calendarWeek()])->assertSessionHas('success');
    expect(ShopOpeningHour::withoutCompanyScope()->pluck('branch_id')->unique()->all())->toBe([$this->leeds->id]);
});

test('tenant isolation: another business can neither see nor change these shops\' calendar', function () {
    $other = Company::factory()->create(['name' => 'Other Stores']);
    $shop = Branch::factory()->forCompany($other)->create(['code' => 'OTH', 'name' => 'Other']);
    $theirs = seasonalEvent($other, $shop, 'Diwali', '2026-11-08', '2026-11-09');
    $otherOwner = F::member($other, CompanyRole::Owner);

    $this->actingAs($otherOwner)->put("/app/calendar/hours/{$this->leeds->id}", ['days' => calendarWeek()])->assertNotFound();
    $this->actingAs($otherOwner)->get("/app/calendar/events/{$this->eid}")->assertNotFound();
    $this->actingAs($this->owner)->get("/app/calendar/events/{$theirs}")->assertNotFound();
    expect(collect(($this->props)('/app/calendar/events?shop=all&when=all', $otherOwner)['events'])->pluck('name')->all())->toBe(['Diwali'])
        ->and(collect(($this->props)('/app/calendar/events?shop=all&when=all')['events'])->pluck('name')->all())->not->toContain('Diwali')
        ->and(($this->props)('/app/calendar/special-days?shop=all&when=all', $otherOwner)['days'])->toBe([])
        ->and(collect(($this->props)('/app/calendar', $otherOwner)['shops'])->pluck('id')->all())->toBe([$shop->id])
        ->and(ShopOpeningHour::withoutCompanyScope()->count())->toBe(0);
});
