<?php

use App\Domain\Anomalies\Actions\DispatchAnomalyAlerts;
use App\Domain\Anomalies\Actions\RecordAnomalies;
use App\Domain\Anomalies\Data\AnomalyFinding;
use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Anomalies\Models\Anomaly;
use App\Domain\Mail\Mailables\AnomalyAlertMail;
use App\Domain\Mail\Mailables\OwnerDigestMail;
use App\Domain\Notifications\Actions\SendDigests;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Models\AlertNotification;
use App\Domain\Notifications\Models\AlertPreference;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Anomalies\AnomalyFixtures as F;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\TillData\TillFixtures as T;

/* Module 6.6: de-duplication of findings, straight-away alerts for serious ones and the digest for the rest. */

beforeEach(function () {
    Mail::fake();
    [$this->company] = T::tenant();
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $this->manager = C::member($this->company, CompanyRole::Manager);
    $this->accountant = C::member($this->company, CompanyRole::Accountant);
    $this->bradfordManager = C::member($this->company, CompanyRole::Manager, T::BRADFORD);
    $this->now = CarbonImmutable::parse(F::DAILY, 'Europe/London')->utc();
    $this->finding = fn (string $day = F::DAY, AnomalySeverity $severity = AnomalySeverity::Medium, AnomalyKind $kind = AnomalyKind::StaffVoids) => new AnomalyFinding(
        kind: $kind, severity: $severity, branchId: T::LEEDS, day: $day,
        periodStart: CarbonImmutable::parse($day.' 00:00', 'Europe/London')->utc(), periodEnd: CarbonImmutable::parse($day.' 23:59', 'Europe/London')->utc(),
        title: $kind->label().' on '.$day, summary: 'Summary.', facts: [['label' => 'Voids', 'value' => '8', 'usual' => '1.0']],
        links: [], score: 5.0, subjectId: $kind->staffLevel() ? F::STAFF_A : null, subjectName: $kind->staffLevel() ? 'Staff A' : null,
    );
    $this->record = fn (AnomalyFinding $f) => app(RecordAnomalies::class)->handle($this->company->id, [$f], $this->now);
    $this->rows = fn () => Anomaly::withoutCompanyScope()->where('company_id', $this->company->id)->get();
});

test('the same finding twice is one row; the same thing on a later day within the cool-down is one more occurrence', function () {
    expect(($this->record)(($this->finding)()))->toHaveCount(1)
        ->and(($this->record)(($this->finding)()))->toHaveCount(0)
        ->and(($this->rows)())->toHaveCount(1);

    expect(($this->record)(($this->finding)('2026-10-09')))->toHaveCount(0);
    $rows = ($this->rows)();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->occurrences)->toBe(2)
        ->and($rows->first()->trading_day)->toStartWith('2026-10-09');

    // Past the 7-day cool-down: a new row.
    ($this->record)(($this->finding)('2026-10-20'));
    expect(($this->rows)())->toHaveCount(2);
});

test('a dismissed finding stays quiet in its cool-down unless it comes back more severe; escalation is returned for alerting', function () {
    ($this->record)(($this->finding)());
    ($this->rows)()->first()->forceFill(['status' => AnomalyStatus::Dismissed, 'status_reason' => 'Training day'])->save();

    expect(($this->record)(($this->finding)('2026-10-08')))->toHaveCount(0)
        ->and(($this->rows)())->toHaveCount(1);

    $raised = ($this->record)(($this->finding)('2026-10-09', AnomalySeverity::High));
    expect($raised)->toHaveCount(1)->and(($this->rows)())->toHaveCount(2);

    // An open row that becomes more severe on a re-run is returned again (to alert).
    $gap = ($this->finding)(F::DAY, AnomalySeverity::Low, AnomalyKind::SalesGap);
    ($this->record)($gap);
    expect(($this->record)(($this->finding)(F::DAY, AnomalySeverity::High, AnomalyKind::SalesGap)))->toHaveCount(1);
});

test('a serious staff-level finding is emailed straight away to owners and managers of the shop, once', function () {
    $rows = ($this->record)(($this->finding)(F::DAY, AnomalySeverity::High));

    $totals = app(DispatchAnomalyAlerts::class)->handle($this->company, $rows, $this->now);
    app(DispatchAnomalyAlerts::class)->handle($this->company, ($this->rows)()->all(), $this->now);

    expect($totals)->toBe(['notified' => 2, 'emailed' => 2]);
    Mail::assertQueued(AnomalyAlertMail::class, 2);
    Mail::assertQueued(AnomalyAlertMail::class, fn (AnomalyAlertMail $m) => $m->hasTo($this->owner->email) && $m->data->shopName === 'Leeds'
        && $m->data->facts['Voids'] === '8 (usual 1.0)' && str_ends_with($m->data->url, '/app/anomalies/'.$rows[0]->id));
    Mail::assertNotQueued(AnomalyAlertMail::class, fn (AnomalyAlertMail $m) => $m->hasTo($this->accountant->email) || $m->hasTo($this->bradfordManager->email));
    expect(AlertNotification::withoutCompanyScope()->where('alert_type', AlertType::UnusualActivity->value)->pluck('user_id')->sort()->values()->all())
        ->toBe([$this->owner->id, $this->manager->id])
        ->and(($this->rows)()->first()->notified_at)->not->toBeNull();
});

test('a serious shop-level finding reaches an accountant who chose it; medium ones never go straight away', function () {
    AlertPreference::withoutCompanyScope()->create(['company_id' => $this->company->id, 'user_id' => $this->accountant->id, 'deliveries' => [AlertType::UnusualActivity->value => 'immediate']]);
    AlertPreference::withoutCompanyScope()->create(['company_id' => $this->company->id, 'user_id' => $this->manager->id, 'deliveries' => [AlertType::UnusualActivity->value => 'digest']]);

    $medium = ($this->record)(($this->finding)(F::DAY, AnomalySeverity::Medium, AnomalyKind::SalesDrop));
    expect(app(DispatchAnomalyAlerts::class)->handle($this->company, $medium, $this->now))->toBe(['notified' => 0, 'emailed' => 0]);

    $high = ($this->record)(($this->finding)(F::DAY, AnomalySeverity::High, AnomalyKind::SalesGap));
    $totals = app(DispatchAnomalyAlerts::class)->handle($this->company, $high, $this->now);

    // Owner (email + bell), accountant (email + bell), manager on digest (bell only).
    expect($totals)->toBe(['notified' => 3, 'emailed' => 2]);
    Mail::assertQueued(AnomalyAlertMail::class, fn (AnomalyAlertMail $m) => $m->hasTo($this->accountant->email));
    Mail::assertNotQueued(AnomalyAlertMail::class, fn (AnomalyAlertMail $m) => $m->hasTo($this->manager->email));
});

test('the 07:00 digest lists new findings of the last day; staff-level ones only for owners and managers', function () {
    AlertPreference::withoutCompanyScope()->create(['company_id' => $this->company->id, 'user_id' => $this->accountant->id, 'deliveries' => [AlertType::UnusualActivity->value => 'digest']]);
    ($this->record)(($this->finding)());
    ($this->record)(($this->finding)(F::DAY, AnomalySeverity::Low, AnomalyKind::SalesDrop));
    F::anomaly($this->company->id, T::LEEDS, AnomalyKind::NegativeStock, AnomalySeverity::Low, ['status' => AnomalyStatus::Dismissed, 'detected_at' => $this->now]);
    F::anomaly($this->company->id, T::LEEDS, AnomalyKind::PriceOverrides, AnomalySeverity::Low, ['detected_at' => $this->now->subDays(2)]);

    app(SendDigests::class)->handle(CarbonImmutable::parse('2026-10-08 07:00', 'Europe/London'));

    $section = function (string $email): ?array {
        $mail = Mail::queued(OwnerDigestMail::class, fn (OwnerDigestMail $m) => $m->hasTo($email))->first();

        return collect($mail?->data->sections ?? [])->firstWhere('type', AlertType::UnusualActivity->value);
    };

    expect($section($this->owner->email))->toMatchArray([
        'title' => 'Unusual activity',
        'summary' => '2 new findings far from normal.',
        'items' => ['Voids by one staff member on 2026-10-07', 'Sales well below normal on 2026-10-07'],
    ])->and($section($this->accountant->email))->toMatchArray(['summary' => '1 new finding far from normal.', 'items' => ['Sales well below normal on 2026-10-07']])
        ->and($section($this->bradfordManager->email))->toBeNull()
        ->and(str_contains((string) $section($this->owner->email)['url'], '/app/anomalies'))->toBeTrue();
});
