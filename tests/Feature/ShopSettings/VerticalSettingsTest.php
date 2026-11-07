<?php

use App\Domain\ShopSettings\Actions\SaveShopSettings;
use App\Domain\ShopSettings\Queries\ShopSettingsPage;
use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Domain\ShopSettings\Support\VerticalSettings;
use App\Domain\Staff\Support\TillPermissionCatalogue;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\TillData\Actions\SaveTillSetting;
use App\Domain\TillData\Enums\SettingScope;
use App\Domain\TillData\Sync\SettingSyncPolicy;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Sync\Till0185Rows as Rows;
use Tests\Feature\TillData\TillFixtures;

/*
 * Till 0.1.85 (W1): the pharmacy, bike workshop and shop-type settings are shown read-only and only to the matching
 * shop; the shop type and the till's BikeBook status are never written by the portal; pharmacy and workshop rights get
 * words when a till sends them, and no other shop is offered them.
 */

beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->page = fn (bool $shop = false) => app(CurrentCompany::class)->runAs($this->company, fn () => ShopSettingsPage::for($this->company->refresh(), $shop ? $this->sync->leeds : null, null));
    $this->sectionIds = fn (array $page) => array_column($page['sections'], 'id');
    $this->errors = function (Closure $call): array {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };
});

it('shows a grocery none of the shop-type sections', function () {
    expect(($this->sectionIds)(($this->page)()))->not->toContain('pharmacy')->not->toContain('workshop')->not->toContain('shopType');
});

it('shows a pharmacy its pharmacy and shop-type settings read-only, as its till sent them', function () {
    $this->sync->push([
        Rows::setting('vertical.pharmacy', 'true', 1),
        Rows::setting('setup.business_type', 'Pharmacy', 2),
        Rows::setting('pharmacy.ods_code', 'FA123', 3),
        Rows::setting('pharmacy.ppc_12_month_price', '114.50', 4),
    ])->assertOk();

    $page = ($this->page)(shop: true);
    $pharmacy = collect($page['sections'])->firstWhere('id', 'pharmacy');

    expect(($this->sectionIds)($page))->toContain('pharmacy', 'shopType')->not->toContain('workshop')
        ->and(collect($pharmacy['settings'])->every(fn (array $s) => $s['readOnly'] === true))->toBeTrue()
        ->and(collect($pharmacy['settings'])->pluck('key'))->not->toContain('pharmacy.dmd_trud_api_key')
        ->and((array) $page['values'])->toMatchArray(['pharmacy.ods_code' => 'FA123', 'pharmacy.ppc_12_month_price' => '114.50', 'vertical.pharmacy' => 'true', 'setup.business_type' => 'Pharmacy']);

    // Edits from the portal are refused (the pharmacy sets them on its till).
    expect(($this->errors)(fn () => app(SaveShopSettings::class)->handle($this->company, $this->sync->leeds, ['pharmacy.ods_code' => 'FB999'])))->toHaveKey('values.pharmacy.ods_code');
});

it('knows a pharmacy by its business type too', function () {
    $this->company->forceFill(['business_type' => BusinessType::Pharmacy])->save();

    expect(($this->sectionIds)(($this->page)()))->toContain('pharmacy')->not->toContain('shopType');
});

it('shows a shop linked to BikeBook its workshop settings read-only', function () {
    $this->sync->push([Rows::setting('integration.workshop', 'true', 1), Rows::setting('workshop.connection_status', 'connected', 2)])->assertOk();

    $page = ($this->page)(shop: true);

    expect(($this->sectionIds)($page))->toContain('workshop')->not->toContain('pharmacy')
        ->and((array) $page['values'])->toMatchArray(['integration.workshop' => 'true', 'workshop.connection_status' => 'connected'])
        ->and(collect(collect($page['sections'])->firstWhere('id', 'workshop')['settings'])->pluck('key'))->not->toContain('workshop.connector_status', 'workshop.bikebook_api_key');
});

it('never writes the shop type or the till\'s BikeBook connection status, nor the connector status yet', function (string $key, string $value) {
    $save = fn () => app(SaveTillSetting::class)->handle($this->company, SettingScope::Branch, $this->sync->leeds, $key, $value);

    expect(($this->errors)($save))->toHaveKey('key')
        ->and(SettingSyncPolicy::tillWritesOnly($key))->toBeTrue();
})->with([
    ['workshop.connection_status', 'connected'],
    ['workshop.connector_status', 'connected'],
    ['vertical.pharmacy', 'true'],
    ['vertical.salon', 'true'],
    ['setup.business_type', 'Pharmacy'],
]);

it('lists only shared keys of the 0.1.85 pack (or till-written ones), never a local-only one', function () {
    $file = TillFixtures::sample('settings-local-only.json');

    foreach (VerticalSettings::catalogue() as $section) {
        foreach ($section['settings'] as $key => $definition) {
            expect(in_array($key, $file['sharedKeys'], true) || SettingSyncPolicy::tillWritesOnly($key))->toBeTrue($key)
                ->and(SettingSyncPolicy::isLocalOnly('branch', $key))->toBeFalse($key)
                ->and(SettingCatalogue::TYPES)->toContain($definition['type'])
                ->and(SettingCatalogue::find($key))->toBeNull();            // never editable through the catalogue
        }
    }

    expect(SettingCatalogue::find('till.show_all_chip'))->toMatchArray(['type' => 'bool', 'default' => 'true'])
        ->and(SettingCatalogue::find('catalogue.brands'))->toMatchArray(['readOnly' => true, 'everyShopOnly' => true]);
});

it('gives pharmacy and workshop rights words when a till sends them, and offers them to no other shop', function () {
    $right = fn (string $key, int $seq) => ['seq' => $seq, 'entity' => 'RolePermission', 'entityId' => '878P6SA19AH0CCWX77Q8BN37VD', 'op' => 'I', 'version' => $seq,
        'companyId' => TillFixtures::COMPANY, 'branchId' => '', 'registerId' => '', 'at' => '2026-11-08T09:00:00Z',
        'payload' => ['roleId' => '01K5T0Q8C4000000000000G002', 'permissionKey' => $key], 'key' => "RolePermission:{$seq}"];
    $groups = fn () => app(CurrentCompany::class)->runAs($this->company, fn () => collect(app(TillPermissionCatalogue::class)->groups())->pluck('permissions', 'label'));

    expect($groups()->keys())->not->toContain('Pharmacy')->not->toContain('Bike workshop');

    $this->sync->push([$right('pharmacy.cd_register', 1), $right('workshop.book', 2)])->assertOk();

    expect(collect($groups()['Pharmacy'])->firstWhere('key', 'pharmacy.cd_register')['label'])->toBe('Controlled drugs register')
        ->and(collect($groups()['Bike workshop'])->firstWhere('key', 'workshop.book')['label'])->toBe('Book repairs')
        ->and(collect($groups()->flatten(1))->pluck('key'))->not->toContain('pharmacy.dispense');
});
