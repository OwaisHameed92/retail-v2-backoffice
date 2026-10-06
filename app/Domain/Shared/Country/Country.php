<?php

namespace App\Domain\Shared\Country;

/**
 * The country profile of this instance (config/country.php, `COUNTRY=GB|PK`). One codebase, one instance per
 * country: a missing, blank or unknown code is GB. Bound as a singleton; read it with `app(Country::class)`.
 *
 *     app(Country::class)->symbol();           // "£"
 *     app(Country::class)->feature('vatReturn'); // true on GB
 *
 * @phpstan-type TaxId array{label: string, pattern: string|null, example: string}
 * @phpstan-type Address array{postcodeLabel: string, postcodeRequired: bool, postcodePattern: string, postcodeExample: string, cityRequired: bool}
 * @phpstan-type Phone array{pattern: string, example: string}
 * @phpstan-type Profile array{name: string, currency: string, currencySymbol: string, currencySymbolSpace: bool, displayDecimals: int, grouping: string, numberLocale: string, dateLocale: string, timezone: string, taxName: string, taxIds: array<string, TaxId>, address: Address, phone: Phone, billing: array{collection: string, manualMethods: list<string>}, features: array<string, bool>}
 */
final class Country
{
    public const DEFAULT = 'GB';

    /**
     * @param  Profile  $profile
     */
    public function __construct(private readonly string $code, private readonly array $profile) {}

    /** The profile picked by `country.code`; GB when that code has no profile. */
    public static function fromConfig(): self
    {
        /** @var array<string, Profile> $profiles */
        $profiles = (array) config('country.profiles', []);
        $code = self::resolveCode(config('country.code'), array_keys($profiles));

        return new self($code, $profiles[$code]);
    }

    /**
     * "pk" → "PK"; blank, unknown or not a string → "GB".
     *
     * @param  list<string>  $known
     */
    public static function resolveCode(mixed $code, array $known): string
    {
        $code = is_string($code) ? strtoupper(trim($code)) : '';

        return in_array($code, $known, true) ? $code : self::DEFAULT;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function is(string $code): bool
    {
        return $this->code === strtoupper($code);
    }

    public function name(): string
    {
        return $this->profile['name'];
    }

    /** ISO 4217 code: "GBP", "PKR". */
    public function currency(): string
    {
        return $this->profile['currency'];
    }

    /** "£", "Rs". */
    public function symbol(): string
    {
        return $this->profile['currencySymbol'];
    }

    /** True when a space separates the symbol from the amount ("Rs 1,250"), false for "£1,250.00". */
    public function symbolSpace(): bool
    {
        return $this->profile['currencySymbolSpace'];
    }

    /** Decimal places shown for money (the stored values keep 2). */
    public function displayDecimals(): int
    {
        return $this->profile['displayDecimals'];
    }

    /** Digit grouping: "thousands" (1,234,567) or "lakh" (12,34,567). */
    public function grouping(): string
    {
        return $this->profile['grouping'];
    }

    /** Whole digits grouped with commas the profile's way: "1234567" → "1,234,567" (GB) or "12,34,567" (PK). */
    public function groupDigits(string $digits): string
    {
        if ($this->grouping() !== 'lakh' || strlen($digits) <= 3) {
            return strrev(implode(',', str_split(strrev($digits), 3)));
        }

        $head = substr($digits, 0, -3);

        return strrev(implode(',', str_split(strrev($head), 2))).','.substr($digits, -3);
    }

    public function numberLocale(): string
    {
        return $this->profile['numberLocale'];
    }

    public function dateLocale(): string
    {
        return $this->profile['dateLocale'];
    }

    /** Where the shops are: "Europe/London", "Asia/Karachi". Storage stays UTC. */
    public function timezone(): string
    {
        return $this->profile['timezone'];
    }

    /**
     * The bound profile's time zone, for code with no Country at hand (phase P1): shop days, trading days, billing
     * and licence dates, mail and CSV times all go through it.
     *
     *     CarbonImmutable::now(Country::zone())->toDateString(); // today in the shops' zone
     */
    public static function zone(): string
    {
        return app(self::class)->timezone();
    }

    /** "VAT", "GST". */
    public function taxName(): string
    {
        return $this->profile['taxName'];
    }

    /**
     * Business tax and registration ids, keyed by field: GB vatNumber, companyNumber; PK ntn, strn, companyNumber.
     *
     * @return array<string, TaxId>
     */
    public function taxIds(): array
    {
        return $this->profile['taxIds'];
    }

    /**
     * @return Address
     */
    public function address(): array
    {
        return $this->profile['address'];
    }

    /**
     * @return Phone
     */
    public function phone(): array
    {
        return $this->profile['phone'];
    }

    /** How monthly fees are collected: "gocardless" (Direct Debit) or "manual" (invoices paid by hand). */
    public function billingCollection(): string
    {
        return $this->profile['billing']['collection'];
    }

    /**
     * Payment methods staff record by hand (GB: PaymentMethod::manual(); PK: bank transfer, JazzCash, Easypaisa, cash).
     *
     * @return list<string>
     */
    public function manualPaymentMethods(): array
    {
        return $this->profile['billing']['manualMethods'];
    }

    /** A country feature flag ("vatReturn", "fbr"); unknown flags are off. */
    public function feature(string $name): bool
    {
        return $this->profile['features'][$name] ?? false;
    }

    /**
     * The profile as shared with every Inertia page (`country`); see resources/js/types/index.ts `CountryProfile`.
     *
     * @return array<string, mixed>
     */
    public function toFrontend(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name(),
            'currency' => $this->currency(),
            'currencySymbol' => $this->symbol(),
            'currencySymbolSpace' => $this->symbolSpace(),
            'displayDecimals' => $this->displayDecimals(),
            'grouping' => $this->grouping(),
            'numberLocale' => $this->numberLocale(),
            'dateLocale' => $this->dateLocale(),
            'timezone' => $this->timezone(),
            'taxName' => $this->taxName(),
            'taxIds' => array_map(fn (array $id) => ['label' => $id['label'], 'example' => $id['example']], $this->taxIds()),
            'address' => [
                'postcodeLabel' => $this->address()['postcodeLabel'],
                'postcodeRequired' => $this->address()['postcodeRequired'],
                'postcodeExample' => $this->address()['postcodeExample'],
                'cityRequired' => $this->address()['cityRequired'],
            ],
            'phoneExample' => $this->phone()['example'],
            'billingCollection' => $this->billingCollection(),
            'features' => $this->profile['features'],
        ];
    }
}
