<?php

use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Module 5.10: parcel activity and carriers, read only (the shops' own rows): counts for the days, parcels waiting
 * now, per carrier, the one-shop rule, who may look, businesses that take no parcels, tenant isolation.
 * "Now" is 30 Sept 2026.
 */

function parcelRow(Company $company, Branch $shop, string $carrier, string $code, string $direction, string $status, string $registered, ?string $handed = null): string
{
    return F::row('parcels', $company, $shop, [
        'carrier_id' => $carrier, 'tracking_code' => $code, 'customer_name' => 'Mrs Begum', 'direction' => $direction, 'status' => $status,
        'registered_at_utc' => $registered, 'registered_by_user_id' => '', 'handed_over_at_utc' => $handed, 'handed_over_by_user_id' => '',
        'hand_over_id_check_note' => $handed !== null && $direction === 'collection' ? 'Driving licence' : '',
    ]);
}

function carrierRow(Company $company, Branch $shop, string $name, int $position): string
{
    return F::row('parcel_carriers', $company, $shop, ['name' => $name, 'is_active' => true, 'position' => $position]);
}

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo('2026-09-30 10:00:00');
    $this->sync = new SyncApiFixtures($this);
    [$this->company, $this->leeds, $this->bradford] = [$this->sync->company, $this->sync->leeds, $this->sync->bradford];
    $this->owner = F::member($this->company, CompanyRole::Owner);
    $this->props = fn (string $url, $user = null) => $this->actingAs($user ?? $this->owner)->get($url)->assertOk()->viewData('page')['props'];

    $this->evri = carrierRow($this->company, $this->leeds, 'Evri', 1);
    $this->inpost = carrierRow($this->company, $this->leeds, 'InPost', 2);
    $bradfordEvri = carrierRow($this->company, $this->bradford, 'Evri', 1);
    parcelRow($this->company, $this->leeds, $this->evri, 'H01ABC123', 'dropOff', 'open', '2026-09-29 12:00:00');
    parcelRow($this->company, $this->leeds, $this->evri, 'H01ABC456', 'collection', 'handedOver', '2026-09-28 09:00:00', '2026-09-29 17:00:00');
    parcelRow($this->company, $this->leeds, $this->inpost, 'INP-777', 'collection', 'open', '2026-09-15 09:00:00');
    parcelRow($this->company, $this->bradford, $bradfordEvri, 'H01BRD001', 'dropOff', 'handedOver', '2026-09-30 08:00:00', '2026-09-30 09:00:00');
});

test('guests go to the login page; staff and accountants get 403; owners and managers may look', function () {
    $this->get('/app/parcels')->assertRedirect('/login');
    $this->actingAs(F::member($this->company, CompanyRole::Staff))->get('/app/parcels')->assertForbidden();
    $this->actingAs(F::member($this->company, CompanyRole::Accountant))->get('/app/parcels')->assertForbidden();
    $this->actingAs(F::member($this->company, CompanyRole::Manager))->get('/app/parcels')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('app/parcels/index'));
});

test('parcel figures for the days, what is waiting now, and each carrier', function () {
    $props = ($this->props)('/app/parcels?shop=all');

    expect($props['summary'])->toMatchArray(['registered' => 3, 'dropOffs' => 2, 'collections' => 1, 'handedOver' => 2, 'waiting' => 2, 'waitingLong' => 1])
        ->and(collect($props['carriers'])->map(fn ($c) => [$c['name'], $c['shop'], $c['dropOffs'], $c['collections'], $c['waiting']])->all())->toBe([
            ['Evri', 'Bradford', 1, 0, 0], ['Evri', 'Leeds Kirkgate', 1, 1, 1], ['InPost', 'Leeds Kirkgate', 0, 0, 1],
        ])
        ->and(collect($props['parcels']['data'])->pluck('trackingCode')->all())->toBe(['H01BRD001', 'H01ABC123', 'H01ABC456'])
        ->and(collect($props['parcels']['data'])->firstWhere('trackingCode', 'H01ABC456'))->toMatchArray(['carrier' => 'Evri', 'idCheck' => 'Driving licence', 'status' => 'handedOver']);

    expect(collect(($this->props)('/app/parcels?shop=all&type=dropOff')['parcels']['data'])->pluck('trackingCode')->all())->toBe(['H01BRD001', 'H01ABC123'])
        ->and(collect(($this->props)('/app/parcels?shop=all&status=open&from=2026-09-01')['parcels']['data'])->pluck('waitingDays', 'trackingCode')->all())->toBe(['H01ABC123' => 0, 'INP-777' => 15])
        ->and(collect(($this->props)("/app/parcels?shop=all&carrier={$this->inpost}&from=2026-09-01")['parcels']['data'])->pluck('trackingCode')->all())->toBe(['INP-777'])
        ->and(collect(($this->props)('/app/parcels?shop=all&search=abc4')['parcels']['data'])->pluck('trackingCode')->all())->toBe(['H01ABC456']);
});

test('a one-shop manager sees their shop\'s parcels and carriers only', function () {
    $manager = F::member($this->company, CompanyRole::Manager, $this->bradford);
    $props = ($this->props)("/app/parcels?shop={$this->leeds->id}", $manager);

    expect($props['summary'])->toMatchArray(['registered' => 1, 'waiting' => 0])
        ->and(collect($props['carriers'])->pluck('shop')->unique()->all())->toBe(['Bradford'])
        ->and($props['filters']['shop'])->toBe($this->bradford->id);
});

test('salons, clothing shops and cash and carries do not see parcels unless a till has them', function () {
    $salon = Company::factory()->create(['name' => 'Hair Studio', 'business_type' => BusinessType::Salon]);
    $branch = Branch::factory()->forCompany($salon)->create(['code' => 'HAI', 'name' => 'Studio']);
    $owner = F::member($salon, CompanyRole::Owner);

    $this->actingAs($owner)->get('/app/parcels')->assertNotFound();
    $this->actingAs($owner)->get('/app/calendar')->assertInertia(fn (Assert $page) => $page
        ->where('abilities', fn ($abilities) => ! collect($abilities)->contains('parcels.view')));

    carrierRow($salon, $branch, 'DPD', 1);
    $this->actingAs($owner)->get('/app/parcels')->assertOk();
});

test('tenant isolation: another business never sees these parcels', function () {
    $other = Company::factory()->create(['name' => 'Other Stores']);
    $branch = Branch::factory()->forCompany($other)->create(['code' => 'OTH', 'name' => 'Other']);
    parcelRow($other, $branch, carrierRow($other, $branch, 'Royal Mail', 1), 'RM-1', 'dropOff', 'open', '2026-09-29 10:00:00');
    $otherOwner = F::member($other, CompanyRole::Owner);

    expect(collect(($this->props)('/app/parcels?shop=all', $otherOwner)['parcels']['data'])->pluck('trackingCode')->all())->toBe(['RM-1'])
        ->and(collect(($this->props)('/app/parcels?shop=all')['parcels']['data'])->pluck('trackingCode')->all())->not->toContain('RM-1')
        ->and(collect(($this->props)('/app/parcels?shop=all')['carriers'])->pluck('name')->all())->not->toContain('Royal Mail');
});
