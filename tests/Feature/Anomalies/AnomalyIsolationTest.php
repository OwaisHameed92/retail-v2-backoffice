<?php

use App\Domain\Anomalies\Actions\DetectAnomalies;
use App\Domain\Anomalies\Actions\DispatchAnomalyAlerts;
use App\Domain\Anomalies\Actions\RecordAnomalies;
use App\Domain\Anomalies\Data\DetectionWindow;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Audit DATA-8: anomalies:detect isolates its businesses. One business whose recording fails is reported with its
 * company id, the others are still recorded and alerted, and the command exits non-zero naming it.
 */

beforeEach(function () {
    Mail::fake();
    Exceptions::fake();
    [$first] = T::tenant();
    $second = Company::factory()->create(['name' => 'Patel News']);
    Branch::factory()->forCompany($second)->create(['code' => 'PTL', 'name' => 'Patel']);
    $this->ids = [$first->id, $second->id];
    $this->failFor = null;   // null: the first business recorded fails
    $this->dispatched = [];

    $this->mock(RecordAnomalies::class, function ($mock) {
        $mock->shouldReceive('handle')->andReturnUsing(function (string $companyId) {
            $this->failFor ??= $companyId;

            return $companyId === $this->failFor ? throw new RuntimeException("Simulated failure for business {$companyId}") : [];
        });
    });
    $this->mock(DispatchAnomalyAlerts::class, function ($mock) {
        $mock->shouldReceive('handle')->andReturnUsing(function (Company $company) {
            $this->dispatched[] = $company->id;

            return ['notified' => 0, 'emailed' => 0];
        });
    });
});

test('one business whose findings cannot be recorded does not stop the others', function () {
    $detect = app(DetectAnomalies::class);
    $totals = $detect->handle(DetectionWindow::DAILY, CarbonImmutable::parse('2026-10-08 06:30', 'Europe/London'));
    $other = $this->ids[0] === $this->failFor ? $this->ids[1] : $this->ids[0];

    expect($totals['companies'])->toBe(2)
        ->and($this->dispatched)->toBe([$other])
        ->and($detect->failedCompanyIds())->toBe([$this->failFor]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === "Simulated failure for business {$this->failFor}");
});

test('anomalies:detect exits non-zero naming the business that failed', function () {
    $this->failFor = $this->ids[1];

    $this->artisan('anomalies:detect', ['--daily' => true])->expectsOutputToContain($this->ids[1])->assertFailed();

    expect($this->dispatched)->toBe([$this->ids[0]]);
});
