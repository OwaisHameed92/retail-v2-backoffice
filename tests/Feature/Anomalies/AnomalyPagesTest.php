<?php

use App\Domain\Ai\Testing\FakeAiClient;
use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Anomalies\Models\Anomaly;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Ai\MorningSummaryFixtures as M;
use Tests\Feature\Anomalies\AnomalyFixtures as F;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\TillData\TillFixtures as T;

/* Module 6.6: the Unusual activity page, status changes, the AI explanation, permissions and business isolation. */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00', 'Europe/London'));
    [$this->company] = T::tenant();
    $this->staffFinding = F::anomaly($this->company->id, T::LEEDS, AnomalyKind::StaffVoids, AnomalySeverity::High);
    $this->shopFinding = F::anomaly($this->company->id, T::LEEDS, AnomalyKind::SalesDrop);
    $this->bradfordFinding = F::anomaly($this->company->id, T::BRADFORD, AnomalyKind::OutOfHours, AnomalySeverity::Low);
    $this->other = Company::factory()->withBranch('OTH', 'Other shop', 1)->create();
    $otherShop = Branch::withoutCompanyScope()->where('company_id', $this->other->id)->firstOrFail();
    $this->otherFinding = F::anomaly($this->other->id, $otherShop->id, AnomalyKind::SalesDrop);
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $this->ids = fn ($response) => collect(C::props($response)['anomalies']['data'])->pluck('id')->sort()->values()->all();
    $this->sorted = fn (Anomaly ...$rows) => collect($rows)->pluck('id')->sort()->values()->all();
});

test('owners see every finding of their business with counts, filters and actions', function () {
    $response = $this->actingAs($this->owner)->get('/app/anomalies?shop=all');

    $response->assertInertia(fn (Assert $page) => $page->component('app/anomalies/index')
        ->where('seesStaff', true)->where('canManage', true)
        ->where('summary', ['new' => 3, 'acknowledged' => 0, 'dismissed' => 0, 'highOpen' => 1])
        ->where('filters.status', 'open')
        ->has('options.kinds', count(AnomalyKind::cases())));
    expect(($this->ids)($response))->toBe(($this->sorted)($this->staffFinding, $this->shopFinding, $this->bradfordFinding));

    $filtered = $this->actingAs($this->owner)->get('/app/anomalies?shop='.T::LEEDS.'&severity=high');
    expect(($this->ids)($filtered))->toBe([$this->staffFinding->id]);

    $this->actingAs($this->owner)->get('/app/anomalies/'.$this->staffFinding->id)
        ->assertInertia(fn (Assert $page) => $page->component('app/anomalies/show')
            ->where('anomaly.id', $this->staffFinding->id)
            ->where('anomaly.subjectName', 'Staff A')
            ->where('anomaly.facts.1', ['label' => 'Voids per 100 sales', 'value' => '30.0', 'usual' => '3.3', 'peers' => '6.7'])
            ->has('anomaly.links', 2)
            ->where('canManage', true));
});

test('accountants see shop-level findings only, read only; staff members cannot open the page', function () {
    $accountant = C::member($this->company, CompanyRole::Accountant);

    $response = $this->actingAs($accountant)->get('/app/anomalies?shop=all');
    $response->assertInertia(fn (Assert $page) => $page->where('seesStaff', false)->where('canManage', false)
        ->where('options.kinds', AnomalyKind::options(false)));
    expect(($this->ids)($response))->toBe(($this->sorted)($this->shopFinding, $this->bradfordFinding));

    $this->actingAs($accountant)->get('/app/anomalies/'.$this->staffFinding->id)->assertNotFound();
    $this->actingAs($accountant)->get('/app/anomalies/'.$this->shopFinding->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('anomaly.links', 1)); // the staff page link needs staff.manage
    $this->actingAs($accountant)->put('/app/anomalies/'.$this->shopFinding->id.'/status', ['status' => 'acknowledged'])->assertForbidden();

    $staff = C::member($this->company, CompanyRole::Staff);
    $this->actingAs($staff)->get('/app/anomalies')->assertForbidden();
    $this->actingAs($staff)->get('/app/anomalies/'.$this->shopFinding->id)->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->get('/app/anomalies')->assertRedirect();
});

test('a one-shop manager sees only their shop\'s findings', function () {
    $manager = C::member($this->company, CompanyRole::Manager, T::BRADFORD);

    $response = $this->actingAs($manager)->get('/app/anomalies?shop='.T::LEEDS);
    $response->assertInertia(fn (Assert $page) => $page->where('filters.shop', T::BRADFORD)->where('filters.shopLocked', true));
    expect(($this->ids)($response))->toBe([$this->bradfordFinding->id]);

    $this->actingAs($manager)->get('/app/anomalies/'.$this->staffFinding->id)->assertNotFound();
    $this->actingAs($manager)->put('/app/anomalies/'.$this->shopFinding->id.'/status', ['status' => 'acknowledged'])->assertNotFound();
    $this->actingAs($manager)->put('/app/anomalies/'.$this->bradfordFinding->id.'/status', ['status' => 'acknowledged'])->assertRedirect();
});

test('another business\'s findings can never be seen or changed', function () {
    $otherOwner = C::member($this->other, CompanyRole::Owner);

    expect(($this->ids)($this->actingAs($otherOwner)->get('/app/anomalies?shop=all')))->toBe([$this->otherFinding->id])
        ->and(($this->ids)($this->actingAs($this->owner)->get('/app/anomalies?shop=all')))->not->toContain($this->otherFinding->id);
    $this->actingAs($this->owner)->get('/app/anomalies/'.$this->otherFinding->id)->assertNotFound();
    $this->actingAs($this->owner)->put('/app/anomalies/'.$this->otherFinding->id.'/status', ['status' => 'dismissed', 'reason' => 'x'])->assertNotFound();
    $this->actingAs($otherOwner)->put('/app/anomalies/'.$this->staffFinding->id.'/status', ['status' => 'acknowledged'])->assertNotFound();

    expect($this->staffFinding->fresh()->status)->toBe(AnomalyStatus::New)
        ->and($this->otherFinding->fresh()->status)->toBe(AnomalyStatus::New);
});

test('acknowledge, dismiss with a reason and reopen are audited and shown in the history', function () {
    $url = '/app/anomalies/'.$this->staffFinding->id;

    $this->actingAs($this->owner)->put($url.'/status', ['status' => 'acknowledged'])->assertRedirect()->assertSessionHas('success');
    $this->actingAs($this->owner)->put($url.'/status', ['status' => 'acknowledged'])->assertSessionHasErrors('status');
    $this->actingAs($this->owner)->put($url.'/status', ['status' => 'dismissed', 'reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->owner)->put($url.'/status', ['status' => 'dismissed', 'reason' => 'Training a new starter'])->assertSessionHas('success');

    $row = $this->staffFinding->fresh();
    expect($row->status)->toBe(AnomalyStatus::Dismissed)
        ->and($row->status_reason)->toBe('Training a new starter')
        ->and($row->status_by)->toBe($this->owner->id)
        ->and(AuditLog::query()->where('subject_id', $row->id)->orderBy('id')->pluck('action')->all())->toBe(['anomaly.acknowledged', 'anomaly.dismissed']);

    $this->actingAs($this->owner)->put($url.'/status', ['status' => 'new'])->assertSessionHas('success');
    $this->actingAs($this->owner)->get($url)->assertInertia(fn (Assert $page) => $page->where('anomaly.status', 'new')
        ->has('history', 3)->where('history.1.reason', 'Training a new starter')->where('history.0.action', 'anomaly.reopened'));
});

test('the plain-words explanation uses only the finding\'s numbers, is stored, and needs AI in the plan', function () {
    config(['ai.enabled' => true]);
    $fake = FakeAiClient::install();
    $url = '/app/anomalies/'.$this->staffFinding->id.'/explain';

    $this->actingAs($this->owner)->postJson($url)->assertOk()->assertJson(['text' => null]);
    expect($fake->requests)->toHaveCount(0); // not in the plan: no call

    M::plan($this->company);
    $fake->replyWith('Staff A voided 9 sales, 30.0 per 100 sales against a usual 3.3 and 6.7 for the team. Check the voided sales first.');
    $this->actingAs($this->owner)->postJson($url)->assertOk()->assertJson(['text' => 'Staff A voided 9 sales, 30.0 per 100 sales against a usual 3.3 and 6.7 for the team. Check the voided sales first.']);
    expect($this->staffFinding->fresh()->explanation)->toStartWith('Staff A voided 9 sales')
        ->and($fake->lastRequest()->messages[0]['content'])->toContain('<finding>');

    $shopUrl = '/app/anomalies/'.$this->shopFinding->id.'/explain';
    $fake->replyWith('Sales were down 42 per cent.');
    $this->actingAs($this->owner)->postJson($shopUrl)->assertOk()->assertJson(['text' => null]);
    expect($this->shopFinding->fresh()->explanation)->toBeNull();

    $staff = C::member($this->company, CompanyRole::Staff);
    $this->actingAs($staff)->postJson($shopUrl)->assertForbidden();
});

test('anomalies:detect runs the hourly and daily checks', function () {
    $this->artisan('anomalies:detect')->expectsOutputToContain('(hourly)')->assertSuccessful();
    $this->artisan('anomalies:detect', ['--daily' => true, '--company' => [$this->company->id]])->expectsOutputToContain('Checked 1 businesses (daily)')->assertSuccessful();
});
