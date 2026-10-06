<?php

use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;
use App\Http\Requests\Admin\TenantRules;
use Tests\TestCase;

uses(TestCase::class);

/** The profile for a COUNTRY value, as config/country.php reads it from the environment. */
function countryForEnv(?string $value): Country
{
    $saved = [$_SERVER['COUNTRY'] ?? null, $_ENV['COUNTRY'] ?? null, getenv('COUNTRY')];

    foreach ([&$_SERVER, &$_ENV] as &$store) {
        if ($value === null) {
            unset($store['COUNTRY']);
        } else {
            $store['COUNTRY'] = $value;
        }
    }
    unset($store);
    putenv($value === null ? 'COUNTRY' : "COUNTRY={$value}");

    try {
        config(['country' => require config_path('country.php')]);

        return Country::fromConfig();
    } finally {
        [$server, $env, $put] = $saved;
        $server === null ? $_SERVER = array_diff_key($_SERVER, ['COUNTRY' => 1]) : $_SERVER['COUNTRY'] = $server;
        $env === null ? $_ENV = array_diff_key($_ENV, ['COUNTRY' => 1]) : $_ENV['COUNTRY'] = $env;
        putenv($put === false ? 'COUNTRY' : "COUNTRY={$put}");
    }
}

function pakistan(): Country
{
    config(['country.code' => 'PK']);

    return Country::fromConfig();
}

// GB golden tests: the UK profile is exactly what the app hard-codes today.

it('is GB by default with the UK values the app uses today', function () {
    $gb = app(Country::class);

    expect($gb->code())->toBe('GB')
        ->and($gb->name())->toBe('United Kingdom')
        ->and($gb->currency())->toBe('GBP')
        ->and($gb->symbol())->toBe('£')
        ->and($gb->symbolSpace())->toBeFalse()
        ->and($gb->displayDecimals())->toBe(2)
        ->and($gb->numberLocale())->toBe('en-GB')
        ->and($gb->dateLocale())->toBe('en-GB')
        ->and($gb->timezone())->toBe('Europe/London')
        ->and($gb->timezone())->toBe(MailFormat::TIMEZONE)
        ->and($gb->timezone())->toBe(config('reporting.timezone'))
        ->and($gb->taxName())->toBe('VAT')
        ->and($gb->billingCollection())->toBe('gocardless')
        ->and($gb->manualPaymentMethods())->toBe(array_map(fn (PaymentMethod $method) => $method->value, PaymentMethod::manual()))
        ->and($gb->grouping())->toBe('thousands')
        ->and($gb->feature('vatReturn'))->toBeTrue()
        ->and($gb->feature('fbr'))->toBeFalse()
        ->and($gb->feature('noSuchFeature'))->toBeFalse();
});

it('pins the GB tax ids, postcode and phone rules to the tenant form rules', function () {
    $gb = app(Country::class);

    expect(array_keys($gb->taxIds()))->toBe(['vatNumber', 'companyNumber'])
        ->and($gb->taxIds()['vatNumber']['label'])->toBe('VAT number')
        ->and($gb->taxIds()['vatNumber']['pattern'])->toBe(TenantRules::VAT_PATTERN)
        ->and($gb->taxIds()['companyNumber']['label'])->toBe('Companies House number')
        ->and($gb->taxIds()['companyNumber']['pattern'])->toBe(TenantRules::COMPANY_NUMBER_PATTERN)
        ->and($gb->address()['postcodeRequired'])->toBeTrue()
        ->and($gb->address()['postcodePattern'])->toBe(TenantRules::POSTCODE_PATTERN)
        ->and($gb->address()['postcodeExample'])->toBe('LS1 6AB')
        ->and(preg_match($gb->address()['postcodePattern'], 'LS1 6AB'))->toBe(1)
        ->and($gb->phone()['pattern'])->toBe(TenantRules::PHONE_PATTERN)
        ->and($gb->phone()['example'])->toBe('07700 900123')
        ->and(preg_match($gb->phone()['pattern'], '07700 900123'))->toBe(1);
});

it('formats GB money as today', function (string|int|float $amount, string $expected) {
    expect(MoneyFormat::format($amount))->toBe($expected);
})->with([
    ['1234.5', '£1,234.50'],
    ['1234.50', '£1,234.50'],
    [0, '£0.00'],
    ['0.00', '£0.00'],
    ['-5', '-£5.00'],
    ['-1234.5', '-£1,234.50'],
    [12, '£12.00'],
    [1.005, '£1.01'],
    ['0.005', '£0.01'],
    ['-0.001', '£0.00'],
    ['1234567.891', '£1,234,567.89'],
    ['999.999', '£1,000.00'],
    ['12345678.9', '£12,345,678.90'],
]);

it('groups GB plain numbers in thousands', function (string|int $value, string $expected) {
    expect(MoneyFormat::number($value))->toBe($expected);
})->with([
    [125000, '125,000'],
    ['1234567.5', '1,234,567.5'],
    ['-1000', '-1,000'],
    [999, '999'],
    [0, '0'],
]);

it('matches the mail and billing money formatters byte for byte on GB', function () {
    foreach (['0', '0.5', '1', '9.99', '12.345', '999.995', '1000', '1234.5', '99999.99', '1234567.8'] as $amount) {
        expect(MoneyFormat::format($amount))->toBe(MailFormat::money($amount))
            ->and(MoneyFormat::format($amount))->toBe(BillingFormat::money($amount));
    }

    // Negative amounts: the sign goes before the symbol, as BillingFormat and the front end's Intl formatters.
    foreach (['-0.5', '-3', '-1234.56'] as $amount) {
        expect(MoneyFormat::format($amount))->toBe(BillingFormat::money($amount));
    }
});

it('shares the GB profile with the front end', function () {
    expect(app(Country::class)->toFrontend())->toBe([
        'code' => 'GB',
        'name' => 'United Kingdom',
        'currency' => 'GBP',
        'currencySymbol' => '£',
        'currencySymbolSpace' => false,
        'displayDecimals' => 2,
        'grouping' => 'thousands',
        'numberLocale' => 'en-GB',
        'dateLocale' => 'en-GB',
        'timezone' => 'Europe/London',
        'taxName' => 'VAT',
        'taxIds' => [
            'vatNumber' => ['label' => 'VAT number', 'example' => 'GB123456789'],
            'companyNumber' => ['label' => 'Companies House number', 'example' => '01234567'],
        ],
        'address' => ['postcodeLabel' => 'Postcode', 'postcodeRequired' => true, 'postcodeExample' => 'LS1 6AB', 'cityRequired' => false],
        'phoneExample' => '07700 900123',
        'billingCollection' => 'gocardless',
        'features' => ['vatReturn' => true, 'fbr' => false],
    ]);
});

it('is one instance per app', function () {
    expect(app(Country::class))->toBe(app(Country::class));
});

// Picking the profile.

it('falls back to GB for a missing, blank or unknown COUNTRY', function (?string $value) {
    expect(countryForEnv($value)->code())->toBe('GB');
})->with([[null], [''], ['  '], ['XX'], ['UK'], ['gbr']]);

it('reads COUNTRY whatever its case or spacing', function () {
    expect(countryForEnv('pk')->code())->toBe('PK')
        ->and(countryForEnv(' PK ')->code())->toBe('PK')
        ->and(countryForEnv('GB')->code())->toBe('GB');
});

it('resolves an unknown code in config to GB', function (mixed $code) {
    config(['country.code' => $code]);

    expect(Country::fromConfig()->code())->toBe('GB');
})->with([['XX'], [''], [null], [42]]);

// Pakistan.

it('has the Pakistan profile', function () {
    $pk = pakistan();

    expect($pk->code())->toBe('PK')
        ->and($pk->is('pk'))->toBeTrue()
        ->and($pk->name())->toBe('Pakistan')
        ->and($pk->currency())->toBe('PKR')
        ->and($pk->symbol())->toBe('Rs')
        ->and($pk->displayDecimals())->toBe(0)
        ->and($pk->numberLocale())->toBe('en-PK')
        ->and($pk->timezone())->toBe('Asia/Karachi')
        ->and($pk->taxName())->toBe('GST')
        ->and(array_map(fn (array $id) => $id['label'], $pk->taxIds()))->toBe(['ntn' => 'NTN', 'strn' => 'STRN', 'companyNumber' => 'SECP registration number'])
        ->and($pk->address()['postcodeRequired'])->toBeFalse()
        ->and($pk->address()['cityRequired'])->toBeTrue()
        ->and(preg_match($pk->address()['postcodePattern'], '54000'))->toBe(1)
        ->and(preg_match($pk->phone()['pattern'], $pk->phone()['example']))->toBe(1)
        ->and($pk->phone()['example'])->toBe('0300 1234567')
        ->and($pk->billingCollection())->toBe('manual')
        ->and($pk->manualPaymentMethods())->toBe(['bankTransfer', 'jazzCash', 'easypaisa', 'cash'])
        ->and($pk->grouping())->toBe('lakh')
        ->and($pk->feature('vatReturn'))->toBeFalse()
        ->and($pk->feature('fbr'))->toBeFalse()
        ->and($pk->toFrontend()['currencySymbol'])->toBe('Rs');
})->group('country-pk');

it('formats Pakistan money in whole rupees with lakh grouping', function (string|int $amount, string $expected) {
    expect(MoneyFormat::format($amount, pakistan()))->toBe($expected);
})->with([
    ['1250', 'Rs 1,250'],
    ['1249.5', 'Rs 1,250'],
    ['1249.49', 'Rs 1,249'],
    ['-1249.5', '-Rs 1,250'],
    [0, 'Rs 0'],
    ['999', 'Rs 999'],
    [125000, 'Rs 1,25,000'],
    ['-125000', '-Rs 1,25,000'],
    ['12345678.9', 'Rs 1,23,45,679'],
    ['1000000000', 'Rs 1,00,00,00,000'],
])->group('country-pk');

it('groups Pakistan plain numbers in lakhs', function (string|int $value, string $expected) {
    expect(MoneyFormat::number($value, pakistan()))->toBe($expected);
})->with([
    [125000, '1,25,000'],
    ['12345678.9', '1,23,45,678.9'],
    ['-100000', '-1,00,000'],
    [1250, '1,250'],
    [99999, '99,999'],
])->group('country-pk');

it('formats with the bound profile when none is given', function () {
    app()->instance(Country::class, pakistan());

    expect(MoneyFormat::format('1250'))->toBe('Rs 1,250');
})->group('country-pk');
