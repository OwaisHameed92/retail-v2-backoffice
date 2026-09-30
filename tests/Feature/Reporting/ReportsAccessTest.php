<?php

use App\Domain\Reporting\Reports\ReportKind;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Reporting\BusinessDashboardHelpers as H;
use Tests\Feature\Reporting\ReportsHelpers as R;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 4.8: who may open the reports, and only their own business and (one-shop users) their own shop. Every
 * basket is the sample's (net £4.53, gross £5.15). "Now" is Wed 23 Sept 2026 18:00 London. Kirkgate today: Leeds 2
 * baskets (tills 1 and 2), Bradford 1; Other Stores 1.
 */

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    [$this->kirkgate, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherShop = Branch::factory()->forCompany($this->other)->create(['code' => 'OTH', 'name' => 'Other shop']);
    $this->otherTill = Register::factory()->forBranch($this->otherShop)->create(['code' => '01', 'is_main_till' => true]);

    H::basket($this->kirkgate, $this->leeds, TillFixtures::TILL_1, 400001, '2026-09-23T09:10:00Z');
    H::basket($this->kirkgate, $this->leeds, TillFixtures::TILL_2, 400002, '2026-09-23T10:10:00Z');
    H::basket($this->kirkgate, $this->bradford, TillFixtures::BRADFORD_TILL, 400003, '2026-09-23T11:10:00Z');
    H::basket($this->other, $this->otherShop, $this->otherTill->id, 400004, '2026-09-23T12:10:00Z');
});

test('guests are sent to log in and till staff get 403 on every reports route', function () {
    $routes = ['/app/reports', '/app/reports/sales', '/app/reports/sales/export', '/app/reports/stock/print'];

    foreach ($routes as $url) {
        $this->get($url)->assertRedirect('/login');
    }

    $staff = R::member($this->kirkgate, CompanyRole::Staff);

    foreach ($routes as $url) {
        $this->actingAs($staff)->get($url)->assertForbidden();
    }
});

test('owner, manager and accountant open the hub and every report; an unknown report is 404', function () {
    foreach ([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant] as $role) {
        $user = R::member($this->kirkgate, $role);
        $this->actingAs($user)->get('/app/reports')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('app/reports/index')->has('reports', count(ReportKind::cases())));
    }

    $owner = R::member($this->kirkgate, CompanyRole::Owner);

    foreach (ReportKind::cases() as $kind) {
        $this->actingAs($owner)->get("/app/reports/{$kind->value}?period=today")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('app/reports/show')->where('report.value', $kind->value)->has('result.summary'));
        $this->actingAs($owner)->get("/app/reports/{$kind->value}/print?period=today")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('app/reports/print')->where('heading.Business', $this->kirkgate->name));
        expect($this->actingAs($owner)->get("/app/reports/{$kind->value}/export?period=today")->assertOk()->streamedContent())->toContain('Business,');
    }

    $this->actingAs($owner)->get('/app/reports/nonsense')->assertNotFound();
});

test('a business sees only its own figures, on screen and in the CSV, whatever till id it asks for', function () {
    $owner = R::member($this->other, CompanyRole::Owner);
    $props = R::props($this->actingAs($owner)->get('/app/reports/sales?period=today&till='.TillFixtures::TILL_1));

    expect(R::figure($props, 'net')['value'])->toBe('4.53')
        ->and($props['filters']['till'])->toBeNull()
        ->and(array_column(R::table($props, 'shops')['rows'], 'label'))->toBe(['Other shop']);

    $csv = $this->actingAs($owner)->get('/app/reports/tenders/export?period=today')->streamedContent();
    expect($csv)->toContain('Other Stores')->not->toContain($this->kirkgate->name)->toContain('5.15');

    // Staff sales of Other Stores list only its own till user's sales.
    $staff = R::props($this->actingAs($owner)->get('/app/reports/staff?period=today'));
    expect(R::table($staff, 'staff')['totals']['net'])->toBe('4.53');
});

test('a one-shop manager sees only their shop in every report, even asking for another shop\'s till', function () {
    $manager = R::member($this->kirkgate, CompanyRole::Manager, TillFixtures::BRADFORD);

    $props = R::props($this->actingAs($manager)->get('/app/reports/sales?period=today&till='.TillFixtures::TILL_1));
    expect($props['context']['restricted'])->toBeTrue()
        ->and($props['context']['branch']['id'])->toBe(TillFixtures::BRADFORD)
        ->and($props['filters']['till'])->toBeNull()
        ->and(R::figure($props, 'net')['value'])->toBe('4.53')
        ->and(R::table($props, 'tills')['rows'])->toHaveCount(1);

    // Switching to Leeds is refused; the reports stay on Bradford.
    $this->actingAs($manager)->post(route('app.branch.switch'), ['branch_id' => TillFixtures::LEEDS])->assertSessionHasErrors('branch_id');

    foreach (['vat' => 'box6', 'discounts' => 'discount', 'products' => 'net', 'hourly' => 'net'] as $report => $figure) {
        $p = R::props($this->actingAs($manager)->get("/app/reports/{$report}?period=today"));
        expect(R::figure($p, $figure)['value'])->toBe($figure === 'discount' ? R::sum('rpt_sales_daily', 'discount', $this->kirkgate->id, [TillFixtures::BRADFORD]) : '4.53');
    }

    $csv = $this->actingAs($manager)->get('/app/reports/sales/export?period=today')->streamedContent();
    expect($csv)->toContain('Shop,Bradford')->not->toContain('Leeds');

    // The owner of the same business sees both shops.
    $owner = R::member($this->kirkgate, CompanyRole::Owner);
    expect(R::figure(R::props($this->actingAs($owner)->get('/app/reports/sales?period=today')), 'net')['value'])->toBe('13.59');
});
