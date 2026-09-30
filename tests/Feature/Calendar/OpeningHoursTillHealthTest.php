<?php

use App\Domain\Calendar\Actions\SaveOpeningHours;
use App\Domain\Calendar\Support\WeeklyHours;
use App\Domain\TillHealth\Support\HealthThresholds;
use App\Domain\TillHealth\Support\TradingHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Feature\TillHealth\TillHealthHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, TillHealthHelpers::class);

/*
 * Module 5.9 → 2.7: "Till offline" alerts follow each shop's opening hours and the till's special days instead of
 * the 08:00–20:00 default. The till is silent from Monday 5 Oct 2026, 12:00 London; 4 silent trading hours raise it.
 */

/** @param array{0: string, 1: string}|null $sunday */
function weekOf(string $opens, string $closes, ?array $sunday = [null, null]): array
{
    $week = [];
    foreach (range(1, 7) as $day) {
        $week[$day] = ['closed' => false, 'opens' => $opens, 'closes' => $closes];
    }
    if ($sunday === null) {
        $week[7] = ['closed' => true];
    }

    return $week;
}

beforeEach(function () {
    Mail::fake();
    config(['till-health.alert_offline_hours' => 4, 'till-health.validate_offline_hours' => 1, 'till-health.validate_online_hours' => 1]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'Europe/London'));
    $this->company = $this->licensedTenant('Khan Mini Mart', 1, 'LDS');
    $this->branch = $this->branchOf($this->company, 'LDS');
    $this->licence = $this->licenceOf($this->registerOf($this->branch, '01'));
    $this->seen($this->licence, CarbonImmutable::parse('2026-10-05 12:00', 'Europe/London'));
    $this->at = fn (string $local) => $this->travelTo(CarbonImmutable::parse($local, 'Europe/London'));
});

test('without opening hours the default applies; with them, outside the shop\'s hours nothing is raised', function () {
    ($this->at)('2026-10-07 09:00');
    $this->refreshHealth();
    expect($this->openAlerts($this->licence))->toBe(['tillOffline']);

    DB::table('licence_alerts')->delete();
    app(SaveOpeningHours::class)->handle($this->company, $this->branch, weekOf('10:00', '16:00'));
    $this->refreshHealth();
    expect($this->openAlerts($this->licence))->toBe([]);

    ($this->at)('2026-10-07 11:00');
    $this->refreshHealth();
    expect($this->openAlerts($this->licence))->toBe(['tillOffline']);
});

test('a closed day and a till special day (closed) have no trading hours', function () {
    app(SaveOpeningHours::class)->handle($this->company, $this->branch, weekOf('07:00', '22:00', null));
    // Silent since Saturday 21:00: one trading hour that day; Sunday is closed.
    $this->seen($this->licence, CarbonImmutable::parse('2026-10-10 21:00', 'Europe/London'));
    ($this->at)('2026-10-11 13:00');
    $this->refreshHealth();
    expect($this->openAlerts($this->licence))->toBe([]);

    // Monday 12 Oct is a till special day: closed all day.
    DB::table('branch_hours_overrides')->insert([
        'id' => '01K5T0Q8C4000000000000BH01', 'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'date' => '2026-10-12',
        'seasonal_event_id' => '', 'seasonal_event_name' => 'Staff training', 'is_closed' => true, 'notes' => '', 'row_version' => 1,
    ]);
    ($this->at)('2026-10-12 13:00');
    $this->refreshHealth();
    expect($this->openAlerts($this->licence))->toBe([]);

    ($this->at)('2026-10-13 11:00');
    $this->refreshHealth();
    expect($this->openAlerts($this->licence))->toBe(['tillOffline']);
});

test('hours past midnight count as trading time', function () {
    $thresholds = HealthThresholds::fromConfig();
    $late = new TradingHours($thresholds, WeeklyHours::everyDay('18:00', '02:00'));
    $at = fn (string $local) => CarbonImmutable::parse($local, 'Europe/London');

    expect($late->isOpen($at('2026-10-07 01:30')))->toBeTrue()
        ->and($late->isOpen($at('2026-10-07 03:00')))->toBeFalse()
        ->and($late->isOpen($at('2026-10-07 12:00')))->toBeFalse()
        ->and($late->hoursBetween($at('2026-10-06 17:00'), $at('2026-10-07 03:00')))->toBe(8.0)
        ->and((new TradingHours($thresholds))->hoursBetween($at('2026-10-06 07:00'), $at('2026-10-07 09:00')))->toBe(13.0);

    // A special day with its own hours wins over the week.
    $special = new TradingHours($thresholds, WeeklyHours::everyDay('09:00', '17:00')->withSpecialDays(['2026-12-24' => ['opens' => '09:00', 'closes' => '13:00'], '2026-12-25' => null]));
    expect($special->hoursBetween($at('2026-12-24 00:00'), $at('2026-12-26 00:00')))->toBe(4.0);
});
