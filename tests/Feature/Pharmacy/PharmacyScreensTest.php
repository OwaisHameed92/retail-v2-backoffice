<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\MedicineClassification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Module 5.10: dispensing records (read only: counts by charge status, exemption, shop and day; no patients) and
 * medicine classes (hub-owned: saved on the portal, pulled by every till). Shown only to businesses that use a
 * pharmacy. "Now" is 30 Sept 2026.
 */

function dispensed(Company $company, Branch $shop, string $at, string $status, string $exemption = 'none', string $charge = '0.00', int $items = 0): string
{
    $id = F::row('dispensing_records', $company, $shop, [
        'patient_customer_id' => '01K5T0Q8C4000000000000C999', 'prescriber_name' => 'Dr A Shah', 'prescriber_registration' => 'GMC 1234567',
        'prescription_date' => substr($at, 0, 10), 'dispensed_at' => $at, 'dispensed_by_user_id' => '', 'dispensed_by_name' => 'Priya',
        'charge_status' => $status, 'exemption' => $exemption, 'charge_amount' => $charge, 'sale_id' => null, 'notes' => '',
    ]);
    for ($i = 0; $i < $items; $i++) {
        F::row('dispensing_items', $company, $shop, [
            'dispensing_record_id' => $id, 'product_id' => F::WATER, 'description' => 'Amoxicillin 500mg', 'quantity' => '21', 'directions' => 'Three a day',
        ]);
    }

    return $id;
}

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo('2026-09-30 10:00:00');
    $this->sync = new SyncApiFixtures($this);
    [$this->company, $this->leeds, $this->bradford] = [$this->sync->company, $this->sync->leeds, $this->sync->bradford];
    $this->company->update(['business_type' => BusinessType::Pharmacy]);
    F::catalogue($this->company);
    $this->owner = F::member($this->company, CompanyRole::Owner);
    $this->props = fn (string $url, $user = null) => $this->actingAs($user ?? $this->owner)->get($url)->assertOk()->viewData('page')['props'];
    $this->classes = fn (bool $bradford = false) => collect(Pull::changes($this->sync->pull(0, bradford: $bradford)))->where('entity', 'MedicineClassification')->values();

    $this->paid = dispensed($this->company, $this->leeds, '2026-09-29 09:00:00', 'paid', 'none', '9.90', 2);
    dispensed($this->company, $this->leeds, '2026-09-29 10:00:00', 'exempt', 'aged60OrOver');
    dispensed($this->company, $this->leeds, '2026-09-28 11:00:00', 'exempt', 'aged60OrOver');
    dispensed($this->company, $this->leeds, '2026-09-28 23:30:00', 'private', 'none', '15.00'); // 29 Sept in London
    dispensed($this->company, $this->leeds, '2026-09-01 09:00:00', 'paid', 'none', '9.90');
    dispensed($this->company, $this->bradford, '2026-09-30 08:00:00', 'exempt', 'prepaymentCertificate');
});

test('guests go to the login page; staff get 403; owner, manager and accountant may look', function () {
    $this->get('/app/pharmacy')->assertRedirect('/login');
    $this->get('/app/pharmacy/medicines')->assertRedirect('/login');
    $staff = F::member($this->company, CompanyRole::Staff);
    $this->actingAs($staff)->get('/app/pharmacy')->assertForbidden();
    $this->actingAs($staff)->get('/app/pharmacy/medicines')->assertForbidden();
    foreach ([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant] as $role) {
        $user = F::member($this->company, $role);
        $this->actingAs($user)->get('/app/pharmacy')->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/pharmacy/dispensing')
            ->where('abilities', fn ($abilities) => collect($abilities)->contains('pharmacy.view')));
        $this->actingAs($user)->get('/app/pharmacy/medicines')->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/pharmacy/medicines'));
    }
});

test('dispensing figures: charge status, exemptions, shops and London days; no patient is sent', function () {
    $props = ($this->props)('/app/pharmacy?shop=all');

    expect($props['summary'])->toBe(['records' => 5, 'items' => 2, 'paid' => 1, 'exempt' => 3, 'private' => 1, 'nhsCharges' => '9.90', 'privateCharges' => '15.00'])
        ->and($props['exemptions'])->toBe([['exemption' => 'aged60OrOver', 'count' => 2], ['exemption' => 'prepaymentCertificate', 'count' => 1]])
        ->and(collect($props['shops'])->map(fn ($s) => [$s['shop'], $s['records'], $s['charges']])->all())->toBe([['Bradford', 1, '0.00'], ['Leeds Kirkgate', 4, '24.90']])
        ->and($props['periods']['unit'])->toBe('day')
        ->and(collect($props['periods']['rows'])->pluck('records', 'period')->filter()->all())->toBe(['2026-09-28' => 1, '2026-09-29' => 3, '2026-09-30' => 1])
        ->and($props['records']['meta']['total'])->toBe(5)
        ->and(collect($props['records']['data'])->firstWhere('id', $this->paid))->toMatchArray(['items' => 2, 'chargeAmount' => '9.90', 'prescriber' => 'Dr A Shah'])
        ->and(json_encode($props['records']))->not->toContain('patient')->not->toContain('C999');

    expect(($this->props)('/app/pharmacy?shop=all&charge=exempt')['records']['meta']['total'])->toBe(3)
        ->and(($this->props)('/app/pharmacy?shop=all&exemption=prepaymentCertificate')['records']['meta']['total'])->toBe(1)
        ->and(($this->props)('/app/pharmacy?shop=all&from=2026-08-01&to=2026-09-30')['periods']['unit'])->toBe('week');
});

test('medicine classes are saved on the portal and every till gets them in its pull; removing sends a D', function () {
    $this->actingAs($this->owner)->put('/app/pharmacy/medicines/'.F::COLA, ['class' => 'pharmacyOnly', 'note' => 'Max 2 packs'])->assertSessionHas('success');

    $sent = ($this->classes)(true)->sole();
    expect($sent['payload'])->toMatchArray(['productId' => F::COLA, 'class' => 'pharmacyOnly', 'note' => 'Max 2 packs', 'rowVersion' => 1])
        ->and(($this->classes)())->toHaveCount(1);

    $this->actingAs($this->owner)->put('/app/pharmacy/medicines/'.F::COLA, ['class' => 'pharmacyOnly', 'note' => 'Max 2 packs'])->assertSessionHas('success', 'Nothing to change.');
    $this->actingAs($this->owner)->put('/app/pharmacy/medicines/'.F::COLA, ['class' => 'prescriptionOnly', 'note' => ''])->assertSessionHas('success');
    expect(MedicineClassification::withoutCompanyScope()->sole())->row_version->toBe(2)
        ->and(($this->classes)()->sole()['payload']['class'])->toBe('prescriptionOnly');

    $list = ($this->props)('/app/pharmacy/medicines?find=cola');
    expect($list['rows']['data'][0])->toMatchArray(['product' => 'Coca-Cola 500ml', 'class' => 'prescriptionOnly'])
        ->and(collect($list['counts'])->pluck('count', 'class')->all())->toBe(['generalSale' => 0, 'pharmacyOnly' => 0, 'prescriptionOnly' => 1])
        ->and($list['candidates'][0])->toMatchArray(['id' => F::COLA, 'class' => 'prescriptionOnly'])
        ->and($list['canEdit'])->toBeTrue();

    $this->actingAs($this->owner)->delete('/app/pharmacy/medicines/'.F::COLA)->assertSessionHas('success');
    expect(($this->classes)()->sole()['op'])->toBe('D')
        ->and(AuditLog::query()->where('action', 'like', 'medicine_class.%')->pluck('action')->sort()->values()->all())->toBe(['medicine_class.created', 'medicine_class.removed', 'medicine_class.updated']);

    // Brought back: the same row again, never a second one.
    $this->actingAs($this->owner)->put('/app/pharmacy/medicines/'.F::COLA, ['class' => 'generalSale']);
    expect(MedicineClassification::withoutCompanyScope()->withTrashed()->count())->toBe(1);
    $this->actingAs($this->owner)->put('/app/pharmacy/medicines/01K5T0Q8C40000000000NOPE01', ['class' => 'generalSale'])->assertSessionHasErrors('productId');
    $this->actingAs($this->owner)->put('/app/pharmacy/medicines/'.F::COLA, ['class' => 'controlled'])->assertSessionHasErrors('class');
});

test('only a user who manages the catalogue for every shop may change medicine classes', function () {
    $accountant = F::member($this->company, CompanyRole::Accountant);
    $oneShop = F::member($this->company, CompanyRole::Manager, $this->leeds);

    expect(($this->props)('/app/pharmacy/medicines', $accountant)['canEdit'])->toBeFalse()
        ->and(($this->props)('/app/pharmacy/medicines', $oneShop)['canEdit'])->toBeFalse();
    $this->actingAs($accountant)->put('/app/pharmacy/medicines/'.F::COLA, ['class' => 'pharmacyOnly'])->assertForbidden();
    $this->actingAs($oneShop)->put('/app/pharmacy/medicines/'.F::COLA, ['class' => 'pharmacyOnly'])->assertForbidden();
    $this->actingAs($oneShop)->delete('/app/pharmacy/medicines/'.F::COLA)->assertForbidden();
    expect(MedicineClassification::withoutCompanyScope()->count())->toBe(0);

    // Their dispensing figures are their shop's only, whatever the URL says.
    expect(($this->props)("/app/pharmacy?shop={$this->bradford->id}", $oneShop)['summary']['records'])->toBe(4);
});

test('businesses without a pharmacy do not see it; a till that dispensed turns it on', function () {
    $shop = Company::factory()->create(['name' => 'Corner Shop', 'business_type' => BusinessType::ConvenienceOffLicence]);
    $branch = Branch::factory()->forCompany($shop)->create(['code' => 'CNR', 'name' => 'Corner']);
    $owner = F::member($shop, CompanyRole::Owner);

    $this->actingAs($owner)->get('/app/pharmacy')->assertNotFound();
    $this->actingAs($owner)->put('/app/pharmacy/medicines/'.F::COLA, ['class' => 'pharmacyOnly'])->assertNotFound();
    $this->actingAs($owner)->get('/app/calendar')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('abilities', fn ($abilities) => ! collect($abilities)->contains('pharmacy.view') && collect($abilities)->contains('parcels.view')));

    dispensed($shop, $branch, '2026-09-29 09:00:00', 'paid', 'none', '9.90');
    $this->actingAs($owner)->get('/app/pharmacy?shop=all')->assertOk();
});

test('tenant isolation: another pharmacy never sees these records or classes', function () {
    $other = Company::factory()->create(['name' => 'Other Chemist', 'business_type' => BusinessType::Pharmacy]);
    $branch = Branch::factory()->forCompany($other)->create(['code' => 'OTH', 'name' => 'Other']);
    dispensed($other, $branch, '2026-09-29 09:00:00', 'private', 'none', '30.00');
    $otherOwner = F::member($other, CompanyRole::Owner);
    $this->actingAs($this->owner)->put('/app/pharmacy/medicines/'.F::COLA, ['class' => 'pharmacyOnly']);

    expect(($this->props)('/app/pharmacy?shop=all', $otherOwner)['summary']['records'])->toBe(1)
        ->and(($this->props)('/app/pharmacy?shop=all')['summary']['records'])->toBe(5)
        ->and(($this->props)('/app/pharmacy/medicines', $otherOwner)['rows']['data'])->toBe([])
        ->and(($this->props)('/app/pharmacy/medicines?find=cola', $otherOwner)['candidates'])->toBe([]);
    $this->actingAs($otherOwner)->put('/app/pharmacy/medicines/'.F::COLA, ['class' => 'generalSale'])->assertSessionHasErrors('productId');
    expect(MedicineClassification::withoutCompanyScope()->sole()->class->value)->toBe('pharmacyOnly');
});
