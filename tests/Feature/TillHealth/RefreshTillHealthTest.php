<?php

use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\TillHealth\Enums\SyncState;
use App\Domain\TillHealth\Enums\TillState;
use App\Domain\TillHealth\Models\BranchHealth;
use App\Domain\TillHealth\Models\TillHealth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Feature\TillHealth\TillHealthHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, TillHealthHelpers::class);

// Wednesday 7 Oct 2026, 12:00 in London (BST): inside the default trading hours 08:00-20:00.
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', 'Europe/London'));
    $this->company = $this->licensedTenant('Khan Mini Mart', 3, 'LDS');
    $this->branch = $this->branchOf($this->company, 'LDS');
    [$this->r1, $this->r2, $this->r3] = [$this->registerOf($this->branch, '01'), $this->registerOf($this->branch, '02'), $this->registerOf($this->branch, '03')];
    [$this->l1, $this->l2, $this->l3] = [$this->licenceOf($this->r1), $this->licenceOf($this->r2), $this->licenceOf($this->r3)];
});

test('a till that validated today is online, a quieter one stale and a silent one offline', function () {
    $this->seen($this->l1, now()->subHour()->toImmutable());
    $this->seen($this->l2, now()->subHours(30)->toImmutable());
    $this->seen($this->l3, now()->subHours(80)->toImmutable());

    $totals = $this->refreshHealth();

    expect($totals)->toMatchArray(['tills' => 3, 'branches' => 1])
        ->and($this->healthOfTill($this->r1)->state)->toBe(TillState::Online)
        ->and($this->healthOfTill($this->r2)->state)->toBe(TillState::Stale)
        ->and($this->healthOfTill($this->r3)->state)->toBe(TillState::Offline)
        ->and($this->healthOfTill($this->r3)->problem_count)->toBe(1)
        ->and($this->healthOfTill($this->r1)->contract_version)->toBe('1')
        ->and($this->healthOfTill($this->r1)->install_id)->toBe($this->l1->device_id);

    $shop = BranchHealth::withoutCompanyScope()->where('branch_id', $this->branch->id)->sole();
    expect($shop->state)->toBe(TillState::Online)
        ->and($shop->sync_state)->toBe(SyncState::NotLinked)
        ->and([$shop->tills, $shop->tills_online, $shop->tills_offline])->toBe([3, 1, 1]);
});

test('a till whose key was never entered is not activated and never alerted', function () {
    $this->refreshHealth();

    expect($this->healthOfTill($this->r1)->state)->toBe(TillState::NotActivated)
        ->and($this->healthOfTill($this->r1)->problem_count)->toBe(0)
        ->and($this->openAlerts($this->l1))->toBe([]);
});

test('the syncing main till is online from its sync contact and carries the push and pull times', function () {
    $this->seen($this->l1, now()->subHours(20)->toImmutable());
    $this->syncStatus($this->branch, ['last_register_id' => $this->r1->id, 'last_push_at' => now()->subMinutes(3), 'last_pull_at' => now()->subMinute(), 'last_app_version' => '0.1.5']);

    $this->refreshHealth();
    $till = $this->healthOfTill($this->r1);

    expect($till->state)->toBe(TillState::Online)
        ->and($till->is_sync_till)->toBeTrue()
        ->and($till->sync_state)->toBe(SyncState::Healthy)
        ->and($till->last_pull_at?->toIso8601String())->toBe(now()->subMinute()->utc()->toIso8601String())
        ->and($till->app_version)->toBe('0.1.4')
        ->and($this->healthOfTill($this->r2)->is_sync_till)->toBeFalse();

    // Sync silent for 5 hours but the PC validated within a day: stale, not offline.
    $this->travel(5)->hours();
    $this->refreshHealth();
    expect($this->healthOfTill($this->r1)->state)->toBe(TillState::Stale);
});

test('an app version below the minimum raises an alert that clears itself after an update', function () {
    config(['licence.api.minimum_app_version' => '0.2.0']);
    $this->seen($this->l1, now()->subHour()->toImmutable(), ['last_app_version' => '0.1.9']);

    expect($this->refreshHealth()['raised'])->toBe(1)
        ->and($this->healthOfTill($this->r1)->app_outdated)->toBeTrue()
        ->and($this->openAlerts($this->l1))->toBe(['appVersionOutdated']);

    $alert = $this->alertOf($this->l1, LicenceAlertType::AppVersionOutdated);
    expect($alert->details['summary'])->toBe('SSPOS 0.1.9 (minimum 0.2.0).');

    // A second run keeps the one open alert.
    $this->travel(5)->minutes();
    expect($this->refreshHealth()['raised'])->toBe(0)
        ->and(LicenceAlert::withoutCompanyScope()->count())->toBe(1);

    $this->l1->forceFill(['last_app_version' => '0.2.1'])->save();
    expect($this->refreshHealth()['resolved'])->toBe(1)
        ->and($this->openAlerts($this->l1))->toBe([])
        ->and($alert->fresh()->resolved_at)->not->toBeNull()
        ->and($alert->fresh()->resolved_by)->toBeNull();
});

test('a till clock beyond the allowed skew raises an alert', function () {
    config(['till-health.clock_skew_seconds' => 300]);
    $this->seen($this->l1, now()->subHour()->toImmutable(), ['till_clock_skew_seconds' => -600]);
    $this->seen($this->l2, now()->subHour()->toImmutable(), ['till_clock_skew_seconds' => 290]);

    $this->refreshHealth();

    expect($this->healthOfTill($this->r1)->clock_skewed)->toBeTrue()
        ->and($this->healthOfTill($this->r2)->clock_skewed)->toBeFalse()
        ->and($this->openAlerts($this->l1))->toBe(['clockSkew'])
        ->and($this->alertOf($this->l1, LicenceAlertType::ClockSkew)->details['summary'])->toBe('Till clock 600 s slow at the last check-in.');
});

test('a rejected row makes sync failing until a clean push; a busy server does not', function () {
    $this->seen($this->l1, now()->subHour()->toImmutable());
    $pushed = now()->subMinutes(2);
    $this->syncStatus($this->branch, [
        'last_register_id' => $this->r1->id, 'last_push_at' => $pushed, 'last_pull_at' => now()->subMinute(),
        'last_error_at' => $pushed, 'last_error_code' => 'row.invalid', 'last_error_message' => 'payload.invalid: Sale total has 3 decimals',
    ]);

    $this->refreshHealth();
    expect($this->healthOfTill($this->r1)->sync_state)->toBe(SyncState::Failing)
        ->and($this->openAlerts($this->l1))->toBe(['syncFailing'])
        ->and($this->alertOf($this->l1, LicenceAlertType::SyncFailing)->details['summary'])->toBe('row.invalid: payload.invalid: Sale total has 3 decimals')
        ->and($this->healthOfTill($this->r2)->sync_state)->toBe(SyncState::NotLinked);

    $this->syncStatus($this->branch, ['last_push_at' => now()]);
    $this->travel(1)->minutes();
    $this->refreshHealth();
    expect($this->healthOfTill($this->r1)->sync_state)->toBe(SyncState::Healthy)
        ->and($this->openAlerts($this->l1))->toBe([]);

    $this->syncStatus($this->branch, ['last_error_at' => now(), 'last_error_code' => 'server.busy', 'last_error_message' => 'Busy']);
    $this->refreshHealth();
    expect($this->healthOfTill($this->r1)->sync_state)->toBe(SyncState::Healthy);
});

test('sync stalls when the main till is online but has not synced for a day, or its backlog keeps growing', function () {
    $this->seen($this->l1, now()->subHour()->toImmutable());
    $this->syncStatus($this->branch, ['last_register_id' => $this->r1->id, 'last_pull_at' => now()->subHours(30)]);

    $this->refreshHealth();
    expect($this->healthOfTill($this->r1)->sync_state)->toBe(SyncState::Stalled)
        ->and($this->openAlerts($this->l1))->toBe(['syncStalled']);

    // Back in sync, then the till reports 10 rows waiting, then 40 at the next check-in: growing.
    $this->syncStatus($this->branch, ['last_pull_at' => now()]);
    $this->l1->forceFill(['diagnostics' => ['pendingSyncRows' => 10], 'diagnostics_at' => now()])->save();
    $this->refreshHealth();
    expect($this->healthOfTill($this->r1)->sync_state)->toBe(SyncState::Healthy)
        ->and($this->openAlerts($this->l1))->toBe([]);

    $this->travel(1)->hours();
    $this->syncStatus($this->branch, ['last_pull_at' => now()]);
    $this->l1->forceFill(['diagnostics' => ['pendingSyncRows' => 40], 'diagnostics_at' => now()])->save();
    $this->refreshHealth();
    $till = $this->healthOfTill($this->r1);
    expect($till->sync_state)->toBe(SyncState::Stalled)
        ->and([$till->pending_sync_rows, $till->pending_sync_rows_previous])->toBe([40, 10]);

    // Refreshing again without a new check-in keeps the comparison with the check-in before.
    $this->travel(5)->minutes();
    $this->syncStatus($this->branch, ['last_pull_at' => now()]);
    $this->refreshHealth();
    expect($this->healthOfTill($this->r1)->pending_sync_rows_previous)->toBe(10);
});

test('till offline alerts wait for trading hours and enough silent trading hours, then stay until the till is back', function () {
    config(['till-health.alert_offline_hours' => 4]);
    // Silent since Sunday 4 Oct 19:00 London; now it is 23:00 on Wednesday: offline, but not trading time.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 23:00', 'Europe/London'));
    $this->seen($this->l1, CarbonImmutable::parse('2026-10-04 19:00', 'Europe/London'));

    $this->refreshHealth();
    expect($this->healthOfTill($this->r1)->state)->toBe(TillState::Offline)
        ->and($this->openAlerts($this->l1))->toBe([]);

    // Thursday 09:00: trading, and plenty of silent trading hours.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00', 'Europe/London'));
    $this->refreshHealth();
    expect($this->openAlerts($this->l1))->toBe(['tillOffline'])
        ->and($this->alertOf($this->l1, LicenceAlertType::TillOffline)->details['summary'])->toBe('Last heard from 4 Oct 2026, 19:00.');

    // At night it stays open; when the till validates again it clears.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 22:00', 'Europe/London'));
    $this->refreshHealth();
    expect($this->openAlerts($this->l1))->toBe(['tillOffline']);

    $this->seen($this->l1, now()->toImmutable());
    $this->refreshHealth();
    expect($this->openAlerts($this->l1))->toBe([]);
});

test('a till offline since just before opening is not alerted until enough trading hours pass', function () {
    config(['till-health.alert_offline_hours' => 4, 'till-health.validate_offline_hours' => 1, 'till-health.validate_online_hours' => 1]);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Europe/London'));
    $this->seen($this->l1, CarbonImmutable::parse('2026-10-07 07:00', 'Europe/London'));

    $this->refreshHealth();
    expect($this->healthOfTill($this->r1)->state)->toBe(TillState::Offline)
        ->and($this->openAlerts($this->l1))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:30', 'Europe/London'));
    $this->refreshHealth();
    expect($this->openAlerts($this->l1))->toBe(['tillOffline']);
});

test('suspended businesses and suspended licences are watched but never alerted', function () {
    config(['licence.api.minimum_app_version' => '9.0.0']);
    $this->seen($this->l1, now()->subHour()->toImmutable());
    $this->seen($this->l2, now()->subHour()->toImmutable(), ['status' => LicenceStatus::Suspended]);

    $this->refreshHealth();
    expect($this->openAlerts($this->l1))->toBe(['appVersionOutdated'])
        ->and($this->openAlerts($this->l2))->toBe([]);

    $this->company->forceFill(['status' => CompanyStatus::Suspended])->save();
    $this->refreshHealth();

    expect($this->healthOfTill($this->r1)->app_outdated)->toBeTrue()
        ->and($this->openAlerts($this->l1))->toBe([]);
});

test('a deactivated till loses its health row and its health alerts clear; licence API alerts are left alone', function () {
    config(['licence.api.minimum_app_version' => '9.0.0']);
    $this->seen($this->l2, now()->subHour()->toImmutable());
    $manual = LicenceAlert::withoutCompanyScope()->create([
        'company_id' => $this->company->id, 'licence_id' => $this->l2->id, 'type' => LicenceAlertType::DeviceMismatch,
        'fingerprint' => str_repeat('a', 64), 'first_seen_at' => now(), 'last_seen_at' => now(), 'count' => 1,
    ]);
    $this->refreshHealth();
    expect($this->openAlerts($this->l2))->toBe(['appVersionOutdated', 'deviceMismatch']);

    $this->r2->forceFill(['is_active' => false])->save();
    $this->travel(5)->minutes();
    $this->refreshHealth();

    expect($this->healthOfTill($this->r2))->toBeNull()
        ->and($this->openAlerts($this->l2))->toBe(['deviceMismatch'])
        ->and($manual->fresh()->resolved_at)->toBeNull();
});

test('a refresh for one business never touches another business\'s rows or alerts', function () {
    config(['licence.api.minimum_app_version' => '9.0.0']);
    $other = $this->licensedTenant('Patel News', 1, 'PNW');
    $otherLicence = $this->firstLicence($other, 'PNW');
    $this->seen($otherLicence, now()->subHour()->toImmutable());
    $this->seen($this->l1, now()->subHour()->toImmutable());

    $this->refreshHealth([$this->company->id]);

    expect($this->healthOfTill($this->r1))->not->toBeNull()
        ->and($this->healthOfTill($this->registerOf($this->branchOf($other, 'PNW'), '01')))->toBeNull()
        ->and($this->openAlerts($otherLicence))->toBe([])
        ->and($this->openAlerts($this->l1))->toBe(['appVersionOutdated']);

    $this->refreshHealth();
    expect($this->openAlerts($otherLicence))->toBe(['appVersionOutdated'])
        ->and(TillHealth::withoutCompanyScope()->where('company_id', $other->id)->count())->toBe(1);
});

test('the command refreshes every business and is scheduled every five minutes', function () {
    $this->seen($this->l1, now()->subHour()->toImmutable());

    $this->artisan('till-health:refresh')
        ->expectsOutputToContain('Checked 3 tills in 1 shops of 1 businesses.')
        ->assertSuccessful();

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'till-health:refresh'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});

test('a refresh runs the same number of queries for 2 businesses as for 6 (no N+1)', function () {
    config(['licence.api.minimum_app_version' => '9.0.0']);
    $count = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->refreshHealth();

        return $queries;
    };

    $this->seen($this->l1, now()->subHour()->toImmutable());
    $this->licensedTenant('Second Shop', 2, 'SEC');
    $this->refreshHealth();
    $few = $count();

    foreach (['THR', 'FOU', 'FIV', 'SIX'] as $code) {
        $company = $this->licensedTenant('Shop '.$code, 2, $code);
        $this->seen($this->firstLicence($company, $code), now()->subHour()->toImmutable());
    }
    $this->refreshHealth(); // raises the new alerts once
    $this->travel(5)->minutes();

    expect($count())->toBe($few);
});
