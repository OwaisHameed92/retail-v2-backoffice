<?php

use App\Domain\Catalogue\Import\ImportColumns;
use App\Domain\Catalogue\Import\RowInterpreter;
use App\Domain\Compliance\Support\ComplianceLookup;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\TillProfile;
use App\Domain\Shared\Support\AppVersion;
use App\Domain\TillData\Enums\AgeRule;
use App\Domain\TillHealth\Support\HealthThresholds;
use App\Http\Requests\Admin\Catalogue\MasterProductRequest;
use App\Http\Requests\App\Catalogue\AddFromCatalogueRequest;
use App\Http\Requests\App\Pricing\EveryShopPriceRequest;
use App\Http\Requests\App\Pricing\PromotionRequest;
use App\Http\Requests\App\SaveProductRequest;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\Shared\CountryModulesFixtures;

/*
 * Pak POS pack 2026-10-07, the profile's till values: age rules (None / Over18 only on PK), the product price limit
 * (9,999,999.99 on PK), and app versions on their own line (Pak POS from 1.0.0, cut from SSPOS 3 0.1.53). GB golden:
 * every rule, price rule and version check exactly as before.
 */

function pakPriceOk(string $rule, string $price): bool
{
    return Validator::make(['price' => $price], ['price' => [$rule]])->passes();
}

test('GB golden: every age rule offered, prices up to 99,999.99 (owner 2026-10-08, the UK till limit), versions compared as before', function () {
    $frontend = app(Country::class)->toFrontend();

    expect($frontend)->not->toHaveKey('ageRules')
        ->and($frontend['priceDigits'])->toBe(5)
        ->and(TillProfile::ageRules())->toBeNull()
        ->and(array_column(ComplianceLookup::ageRules(), 'value'))->toBe(array_values(array_map(fn (AgeRule $r) => $r->value, array_filter(AgeRule::cases(), fn (AgeRule $r) => $r !== AgeRule::None))))
        ->and(ImportColumns::fields()['age_rule']['help'])->toBe('16, 18 or a till age rule such as tobaccoGenerational. Empty or "none" for none.');

    $rule = 'regex:/^\d{1,5}(\.\d{1,2})?$/';
    expect(TillProfile::priceRule())->toBe($rule)
        ->and((new SaveProductRequest)->rules()['sell_price'])->toBe(['required', $rule])
        ->and((new EveryShopPriceRequest)->rules()['price'])->toBe(['required', $rule])
        ->and((new AddFromCatalogueRequest)->rules()['items.*.sell_price'])->toBe(['nullable', $rule])
        ->and((new PromotionRequest)->rules()['deal_price'])->toBe(['nullable', $rule])
        ->and((new MasterProductRequest)->rules()['rrp'])->toBe(['nullable', 'numeric', 'min:0', 'max:99999.99'])
        ->and(pakPriceOk($rule, '99999.99'))->toBeTrue()
        ->and(pakPriceOk($rule, '100000.00'))->toBeFalse()
        ->and(RowInterpreter::decimal('99,999.99', 2))->toBe('99999.99')
        ->and(RowInterpreter::decimal('100,000.00', 2))->toBeNull();

    foreach ([['1.0.0', '0.1.53'], ['0.1.52', '0.1.53'], ['0.1.60', '0.1.0'], ['1.0.0', '1.0.1']] as [$version, $gate]) {
        expect(TillProfile::compareAppVersion($version, $gate))->toBe(AppVersion::compare($version, $gate));
    }

    expect(TillProfile::minimumAppVersion('0.1.0'))->toBe('0.1.0')
        ->and(HealthThresholds::fromConfig()->minimumAppVersion)->toBe('0.1.0')
        ->and(TillProfile::appName())->toBe('SSPOS');
});

test('PK: the pickers offer None and Over18 only (a stored rule stays), compliance filters on 18, import help says 18', function () {
    CountryModulesFixtures::pakistan();

    expect(app(Country::class)->toFrontend()['ageRules'])->toBe(['none', 'over18'])
        ->and(TillProfile::offersAgeRule('over18'))->toBeTrue()
        ->and(TillProfile::offersAgeRule('tobaccoGenerational'))->toBeFalse()
        ->and(ComplianceLookup::ageRules())->toBe([['value' => 'over18', 'label' => '18+']])
        ->and(ComplianceLookup::ageRule('tobaccoGenerational'))->toBe('Tobacco (born 2009 or later)') // a till's stored value, shown as it is
        ->and(ImportColumns::fields()['age_rule']['help'])->toBe('18 for an age check. Empty or "none" for none.')
        ->and(Validator::make(['age_rule' => 'nicotine'], ['age_rule' => (new SaveProductRequest)->rules()['age_rule']])->passes())->toBeTrue(); // kept as stored
})->group('country-pk');

test('PK: a product price may be up to 9,999,999.99 in every price form, the import and the browser check', function () {
    CountryModulesFixtures::pakistan();
    $rule = 'regex:/^\d{1,7}(\.\d{1,2})?$/';

    expect(TillProfile::priceRule())->toBe($rule)
        ->and(app(Country::class)->toFrontend()['priceDigits'])->toBe(7)
        ->and((new SaveProductRequest)->rules()['sell_price'])->toBe(['required', $rule])
        ->and((new SaveProductRequest)->rules()['units.*.sell_price_inc_vat'])->toBe(['required', $rule])
        ->and((new EveryShopPriceRequest)->rules()['price'])->toBe(['required', $rule])
        ->and((new AddFromCatalogueRequest)->rules()['items.*.sell_price'])->toBe(['nullable', $rule])
        ->and((new PromotionRequest)->rules()['deal_price'])->toBe(['nullable', $rule])
        ->and((new MasterProductRequest)->rules()['rrp'])->toBe(['nullable', 'numeric', 'min:0', 'max:9999999.99'])
        ->and(pakPriceOk($rule, '9999999.99'))->toBeTrue()
        ->and(pakPriceOk($rule, '10000000.00'))->toBeFalse()
        ->and(RowInterpreter::decimal('Rs 99,99,999.99', 2))->toBe('9999999.99')
        ->and(RowInterpreter::decimal('1,00,00,000', 2))->toBeNull()
        ->and(RowInterpreter::decimal('12000000.1234', 4))->toBe('12000000.1234'); // costs keep their limit
})->group('country-pk');

test('PK: Pak POS versions are their own line; against a 0.x gate they count as SSPOS 0.1.53, never number for number', function () {
    CountryModulesFixtures::pakistan();

    expect(TillProfile::compareAppVersion('1.0.0', '0.1.53'))->toBe(0)
        ->and(TillProfile::compareAppVersion('1.4.2', '0.1.40'))->toBe(1)
        ->and(TillProfile::compareAppVersion('1.0.0', '0.1.60'))->toBe(-1)   // a gate above the baseline is not met
        ->and(TillProfile::compareAppVersion('1.0.0', '1.0.1'))->toBe(-1)    // Pak POS against Pak POS: as they are
        ->and(TillProfile::compareAppVersion('1.2.0', '1.0.0'))->toBe(1)
        ->and(TillProfile::compareAppVersion('0.1.52', '0.1.53'))->toBe(-1)  // an SSPOS 3 till: as it is
        ->and(TillProfile::minimumAppVersion('0.1.0'))->toBe('1.0.0')        // a 0.x minimum is sent on the Pak POS line
        ->and(TillProfile::minimumAppVersion('1.2.0'))->toBe('1.2.0')
        ->and(HealthThresholds::fromConfig()->minimumAppVersion)->toBe('1.0.0')
        ->and(AppVersion::matchesAny('1.0.0', ['0.1.*', '0.1.53']))->toBeFalse() // a blocked SSPOS build never blocks Pak POS
        ->and(TillProfile::appName())->toBe('Pak POS');
})->group('country-pk');
