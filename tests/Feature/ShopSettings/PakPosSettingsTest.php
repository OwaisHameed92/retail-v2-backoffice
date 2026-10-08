<?php

use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Domain\TillData\Sync\SettingSyncPolicy;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Shared\CountryModulesFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;
use Tests\Support\PakPosContract;

uses(TenancyTestHelpers::class);

/*
 * Pak POS pack 2026-10-07: the Till settings page of a Pakistan instance (`country-pk`) has the Pak POS line's own
 * shared settings (currency sign, phone wallets, quick cash, WhatsApp country code), cash rounded to the rupee, the
 * money settings' rupee start values, and none of the UK-only settings the Pakistan till never shows. GB golden: the
 * catalogue exactly as written in catalogue.php.
 */

const PAK_HIDDEN = ['till.keypad_price_in_pence', 'compliance.challenge25_age', 'compliance.challenge25_wording', 'compliance.nicotine_products_gated', 'compliance.energy_drink_age_gate'];
const PAK_ADDED = ['shop.currency_symbol', 'payments.allow_wallets', 'payments.quick_cash', 'messaging.whatsapp_country_code'];

function pakSettingError(string $key, mixed $value): ?string
{
    try {
        SettingCatalogue::normalise($key, $value);
    } catch (ValidationException $e) {
        return (string) collect($e->errors())->flatten()->first();
    }

    return null;
}

test('GB golden: the catalogue is catalogue.php exactly; no Pak POS setting, UK labels and limits, no rupee start values', function () {
    $all = SettingCatalogue::all();

    expect(SettingCatalogue::sections())->toBe(require app_path('Domain/ShopSettings/catalogue.php'))
        ->and(array_intersect(array_keys($all), PAK_ADDED))->toBe([])
        ->and($all)->toHaveKeys(PAK_HIDDEN)
        ->and([$all['till.keypad_price_in_pence']['label'], $all['payments.round_cash_to_5p']['label']])->toBe(['Type prices in pence', 'Round cash to 5p'])
        ->and($all['payments.round_cash_to_5p'])->not->toHaveKey('default')
        ->and($all['cash.default_float'])->toBe(['label' => 'Usual float', 'type' => 'money', 'min' => 0, 'max' => 10000, 'help' => 'The cash left in the drawer at the start of the day.'])
        ->and($all['till.bag_charge_amount']['max'])->toBe(5)
        ->and($all['compliance.challenge25_age']['help'])->toBe('Challenge 25: usually 25.')
        ->and(pakSettingError('shop.currency_symbol', 'Rs'))->toBe('This setting cannot be changed from the portal.')
        ->and(pakSettingError('cash.default_float', '10000.01'))->toBe('Usual float: enter a value between 0 and 10000.');
});

test('PK: the UK-only settings are not on the page and cannot be saved; the tills keep their values', function () {
    CountryModulesFixtures::pakistan();
    $all = SettingCatalogue::all();

    foreach (PAK_HIDDEN as $key) {
        expect($all)->not->toHaveKey($key)
            ->and(pakSettingError($key, $key === 'compliance.challenge25_wording' ? 'Under 25?' : 'true'))->toBe('This setting cannot be changed from the portal.');
    }

    expect(collect($all)->keys()->filter(fn (string $key) => str_starts_with($key, 'compliance.mup_') || str_starts_with($key, 'compliance.generational_'))->all())->toBe([])
        ->and(json_encode(SettingCatalogue::sections()))->not->toContain('Challenge 25')->not->toContain('pence');
})->group('country-pk');

test('PK: the Pak POS settings, cash rounded to the rupee, and the rupee start values and limits', function () {
    CountryModulesFixtures::pakistan();
    $all = SettingCatalogue::all();

    expect($all['shop.currency_symbol'])->toMatchArray(['type' => 'text', 'max' => 6, 'default' => 'Rs', 'everyShopOnly' => true])
        ->and($all['payments.allow_wallets'])->toMatchArray(['label' => 'Phone wallets', 'type' => 'bool', 'default' => 'true'])
        ->and($all['payments.quick_cash'])->toMatchArray(['type' => 'text', 'default' => '100,500,1000,5000'])
        ->and($all['messaging.whatsapp_country_code'])->toMatchArray(['type' => 'int', 'default' => '92'])
        ->and($all['payments.round_cash_to_5p'])->toMatchArray(['label' => 'Round cash to the rupee', 'default' => 'true'])
        ->and(array_map(fn (string $key) => $all[$key]['default'] ?? null, [
            'till.clear_cart_requires_pin_over', 'till.refund_without_receipt_max', 'till.bag_charge_amount', 'cash.default_float',
            'cash.variance_alert_over', 'cash.high_value_variance_threshold', 'cash.safe_drop_prompt_over', 'staff.discount_daily_cap', 'staff.discount_weekly_cap',
        ]))->toBe(['2000.00', '2000.00', '10.00', '5000.00', '500.00', '1000.00', '50000.00', '1000.00', '3000.00'])
        ->and([$all['cash.default_float']['max'], $all['till.bag_charge_amount']['max'], $all['cash.safe_drop_prompt_over']['max']])->toBe([1000000, 500, 10000000]);

    expect(SettingCatalogue::normalise('shop.currency_symbol', ' Rs '))->toBe('Rs')
        ->and(pakSettingError('shop.currency_symbol', 'Rupees!'))->toBe('Currency sign: at most 6 characters.')
        ->and(SettingCatalogue::normalise('payments.allow_wallets', false))->toBe('false')
        ->and(SettingCatalogue::normalise('messaging.whatsapp_country_code', '92'))->toBe('92')
        ->and(SettingCatalogue::normalise('cash.default_float', 'Rs 5000'))->toBe('5000.00')
        ->and(pakSettingError('cash.default_float', '1000001'))->toBe('Usual float: enter a value between 0 and 1000000.');
})->group('country-pk');

test('PK contract: every setting on the Pakistan page is a shared key of the Pak POS pack and never a till-only one', function () {
    CountryModulesFixtures::pakistan();
    $shared = PakPosContract::json('samples/settings-local-only.json')['sharedKeys'];

    expect(array_values(array_diff(array_keys(SettingCatalogue::all()), $shared)))->toBe([])
        ->and($shared)->toContain(...PAK_ADDED);

    foreach (PAK_ADDED as $key) {
        expect(SettingSyncPolicy::isLocalOnly('company', $key))->toBeFalse($key);
    }
})->group('country-pk');

test('PK: the Till settings page shows the Pak POS settings with their rupee start values', function () {
    $f = CountryModulesFixtures::build($this, fn ($company, $role) => $this->memberOf($company, $role));
    CountryModulesFixtures::pakistan();

    $sections = collect($this->actingAs($f->owner)->get('/app/settings')->assertOk()->inertiaProps('sections'));
    $settings = $sections->flatMap(fn (array $s) => $s['settings'])->keyBy('key');

    expect($settings->keys()->all())->toContain(...PAK_ADDED)->not->toContain(...PAK_HIDDEN)
        ->and($settings['cash.default_float']['default'])->toBe('5000.00')
        ->and($settings['shop.currency_symbol']['default'])->toBe('Rs');
})->group('country-pk');
