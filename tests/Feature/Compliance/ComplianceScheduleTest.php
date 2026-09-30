<?php

use App\Domain\Compliance\Support\Expiry;
use App\Domain\Compliance\Support\MissedChecks;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Enums\DiaryCheckDefinitionSchedule as Schedule;
use Carbon\CarbonImmutable;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Compliance\ComplianceFixtures as F;
use Tests\Feature\TillData\TillFixtures;

/* Module 5.7: missed diary checks per schedule, and expiry reminders for training and licences. */

beforeEach(function () {
    // Thursday 15 October 2026, 12:00 London (11:00 UTC, BST).
    $this->now = CarbonImmutable::parse('2026-10-15 12:00', 'Europe/London');
    $this->travelTo($this->now);
    [$this->company] = TillFixtures::tenant();
    F::staff($this->company->id);
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $this->definition = fn (string $tag, string $schedule, array $row = []) => F::row('diary_check_definitions', [
        'id' => F::id('D'.$tag), 'company_id' => $this->company->id, 'branch_id' => TillFixtures::LEEDS, 'name' => $tag, 'category' => 'Food safety',
        'schedule' => $schedule, 'is_active' => true, 'sort_order' => 0, 'created_at' => '2026-09-01 08:00:00', ...$row,
    ]);
    $this->record = fn (string $tag, string $definition, string $at, bool $passed = true) => F::row('diary_check_records', [
        'id' => F::id('K'.$tag), 'company_id' => $this->company->id, 'branch_id' => TillFixtures::LEEDS, 'diary_check_definition_id' => F::id('D'.$definition),
        'recorded_at' => $at, 'recorded_by_user_id' => F::ALI, 'value' => '4°C', 'passed' => $passed,
    ]);
});

test('daily periods are London days: done, missed once ended, due while running', function () {
    $periods = MissedChecks::periods(Schedule::Daily, '2026-10-13', '2026-10-20', [], $this->now);
    expect(array_column($periods, 'label'))->toBe(['2026-10-13', '2026-10-14', '2026-10-15']); // never past today

    // 23:30 London on the 13th is 22:30 UTC: still the 13th.
    $states = MissedChecks::evaluate($periods, [CarbonImmutable::parse('2026-10-13 22:30:00', 'UTC')], $this->now);
    expect(array_column($states, 'state'))->toBe(['done', 'missed', 'due']);
});

test('weekly periods run Monday to Sunday and per-shift periods follow the shop\'s shifts', function () {
    $weeks = MissedChecks::periods(Schedule::Weekly, '2026-10-01', '2026-10-15', [], $this->now);
    expect(array_column($weeks, 'label'))->toBe(['week:2026-09-28', 'week:2026-10-05', 'week:2026-10-12']);
    $states = MissedChecks::evaluate($weeks, [CarbonImmutable::parse('2026-10-11 20:00:00', 'UTC')], $this->now);
    expect(array_column($states, 'state'))->toBe(['missed', 'done', 'due']);

    $shifts = [
        [CarbonImmutable::parse('2026-10-14 07:00:00', 'UTC'), CarbonImmutable::parse('2026-10-14 15:00:00', 'UTC')],
        [CarbonImmutable::parse('2026-10-14 15:00:00', 'UTC'), CarbonImmutable::parse('2026-10-14 22:00:00', 'UTC')],
        [CarbonImmutable::parse('2026-10-15 07:00:00', 'UTC'), null],
    ];
    $periods = MissedChecks::periods(Schedule::PerShift, '2026-10-14', '2026-10-15', $shifts, $this->now);
    $states = MissedChecks::evaluate($periods, [CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC')], $this->now);
    expect(array_column($states, 'state'))->toBe(['done', 'missed', 'due']);
});

test('the diary screen counts due, done and missed per check from the day it was set up, and lists the misses', function () {
    ($this->definition)('Fridge', 'daily', ['created_at' => '2026-10-12 08:00:00']); // set up on Monday
    ($this->definition)('Fire exits', 'weekly');
    ($this->definition)('Old', 'daily', ['is_active' => false]);
    ($this->record)('1', 'Fridge', '2026-10-12 09:00:00');
    ($this->record)('2', 'Fridge', '2026-10-14 09:00:00', false);
    ($this->record)('3', 'Fire exits', '2026-10-05 09:00:00');

    $p = C::props($this->actingAs($this->owner)->get('/app/compliance/diary?shop=all&from=2026-10-01&to=2026-10-15'));
    $rows = collect($p['definitions'])->keyBy('name');

    // Fridge: 12th done, 13th missed, 14th done (a failed reading still counts as done), 15th due.
    expect($rows['Fridge'])->toMatchArray(['due' => 4, 'done' => 2, 'missed' => 1, 'dueNow' => true])
        // Fire exits: weeks of 28 Sep (missed), 5 Oct (done), 12 Oct (due).
        ->and($rows['Fire exits'])->toMatchArray(['due' => 3, 'done' => 1, 'missed' => 1, 'dueNow' => true, 'schedule' => 'weekly'])
        ->and($rows['Old'])->toMatchArray(['due' => 0, 'missed' => 0, 'active' => false])
        ->and($p['summary'])->toBe(['definitions' => 2, 'due' => 7, 'done' => 3, 'missed' => 2, 'failed' => 1])
        ->and(array_column($p['missed'], 'period'))->toBe(['2026-10-13', 'week:2026-09-28'])
        ->and($p['records']['meta']['total'])->toBe(3);

    // The overview reminds about the last 7 days' misses.
    $attention = C::props($this->actingAs($this->owner)->get('/app/compliance?shop=all'))['attention']['items'];
    expect(collect($attention)->where('label', 'Check')->pluck('text')->all())->toBe(['Fridge · Leeds missed once in the last 7 days']);
});

test('expiry reminders: expired, expiring within 30 days (training) or 60 days (licences), in date and never', function () {
    $today = CarbonImmutable::parse('2026-10-15');
    expect(Expiry::status(null, 30, $today))->toBe('none')
        ->and(Expiry::status(CarbonImmutable::parse('2026-10-14'), 30, $today))->toBe('expired')
        ->and(Expiry::status(CarbonImmutable::parse('2026-10-15'), 30, $today))->toBe('expiring')
        ->and(Expiry::status(CarbonImmutable::parse('2026-11-14'), 30, $today))->toBe('expiring')
        ->and(Expiry::status(CarbonImmutable::parse('2026-11-15'), 30, $today))->toBe('valid')
        ->and(Expiry::daysLeft(CarbonImmutable::parse('2026-10-20'), $today))->toBe(5);

    $id = $this->company->id;
    $training = fn (string $tag, ?string $expires) => F::row('training_records', ['id' => F::id('T'.$tag), 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'user_id' => F::ALI, 'topic' => 'Topic '.$tag, 'trained_on' => '2025-01-01', 'expires_on' => $expires]);
    $licence = fn (string $tag, ?string $expires) => F::row('compliance_licences', ['id' => F::id('C'.$tag), 'company_id' => $id, 'branch_id' => TillFixtures::BRADFORD, 'licence_type' => 'Type '.$tag, 'number' => 'N'.$tag, 'issued_on' => '2020-01-01', 'expires_on' => $expires]);
    $training('A', '2026-10-01');  // expired
    $training('B', '2026-11-01');  // expiring
    $training('C', '2026-12-31');  // in date (beyond 30 days)
    $training('D', null);          // never
    $licence('A', '2026-12-01');   // expiring (within 60 days)
    $licence('B', '2027-06-01');   // in date

    $t = C::props($this->actingAs($this->owner)->get('/app/compliance/training?shop=all'));
    expect(collect($t['records']['data'])->pluck('status', 'topic')->all())
        ->toBe(['Topic A' => 'expired', 'Topic B' => 'expiring', 'Topic C' => 'valid', 'Topic D' => 'none'])
        ->and($t['summary'])->toBe(['total' => 4, 'staff' => 1, 'expiring' => 1, 'expired' => 1])
        ->and(array_column(C::props($this->actingAs($this->owner)->get('/app/compliance/training?shop=all&status=valid'))['records']['data'], 'topic'))->toBe(['Topic C', 'Topic D']);

    $l = C::props($this->actingAs($this->owner)->get('/app/compliance/licences?shop=all'));
    expect(collect($l['licences']['data'])->pluck('status', 'type')->all())->toBe(['Type A' => 'expiring', 'Type B' => 'valid'])
        ->and($l['licences']['data'][0]['daysLeft'])->toBe(47);

    $o = C::props($this->actingAs($this->owner)->get('/app/compliance?shop=all'));
    expect(collect($o['attention']['items'])->map(fn ($i) => [$i['label'], $i['tone'], $i['text']])->all())->toBe([
        ['Training', 'danger', 'Ali Khan: Topic A · Leeds expired 1 Oct 2026'],
        ['Licence', 'warning', 'Type A NA · Bradford expires 1 Dec 2026 (47 days)'],
        ['Training', 'warning', 'Ali Khan: Topic B · Leeds expires 1 Nov 2026 (17 days)'],
    ])
        ->and($o['figures'])->toMatchArray(['licencesDue' => 1, 'trainingDue' => 2, 'missedChecks' => 0, 'openRecalls' => 0]);
});
