<?php

/*
| Country profiles (Pakistan plan, phase P0). One codebase, one instance per country: `COUNTRY` picks the profile
| of this instance. A missing, blank or unknown value is GB, so the UK portal behaves exactly as before.
| Read it through App\Domain\Shared\Country\Country, never with config() in feature code.
|
| The GB values are today's UK behaviour, copied from the code that hard-codes them (later phases switch that code
| to the profile): TenantRules patterns and messages, MailFormat / BillingFormat money, the 'Europe/London' and
| 'en-GB' formatters, GoCardless Direct Debit billing (docs/billing-flow.md).
*/

return [
    'code' => strtoupper(trim((string) env('COUNTRY', 'GB'))),

    'profiles' => [
        'GB' => [
            'name' => 'United Kingdom',
            'currency' => 'GBP',
            'currencySymbol' => '£',
            // "£1,234.50": no space between symbol and amount.
            'currencySymbolSpace' => false,
            'displayDecimals' => 2,
            // Digit grouping: "thousands" (1,234,567) or "lakh" (12,34,567).
            'grouping' => 'thousands',
            'numberLocale' => 'en-GB',
            'dateLocale' => 'en-GB',
            'timezone' => 'Europe/London',
            'taxName' => 'VAT',
            'taxIds' => [
                // TenantRules::VAT_PATTERN / COMPANY_NUMBER_PATTERN.
                'vatNumber' => [
                    'label' => 'VAT number',
                    'pattern' => '/^(GB|XI)(\d{9}|\d{12}|GD\d{3}|HA\d{3})$/',
                    'example' => 'GB123456789',
                ],
                'companyNumber' => [
                    'label' => 'Companies House number',
                    'pattern' => '/^([A-Z]{2}\d{6}|\d{8})$/',
                    'example' => '01234567',
                ],
            ],
            'address' => [
                // TenantRules::POSTCODE_PATTERN. Required on the profile: a UK shop always has one (forms still
                // validate it as today until phase P4).
                'postcodeLabel' => 'Postcode',
                'postcodeRequired' => true,
                'postcodePattern' => '/^[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}$/',
                'postcodeExample' => 'LS1 6AB',
                'cityRequired' => false,
            ],
            'phone' => [
                // TenantRules::PHONE_PATTERN; the example used by the lead and trial forms.
                'pattern' => '/^[0-9+()\s-]{7,20}$/',
                'example' => '07700 900123',
            ],
            'billing' => [
                // Monthly fees by GoCardless Direct Debit.
                'collection' => 'gocardless',
                // Methods staff record by hand: PaymentMethod::manual().
                'manualMethods' => ['cash', 'card', 'bankTransfer', 'other'],
            ],
            'features' => [
                'vatReturn' => true,
                'fbr' => false,
            ],
        ],

        'PK' => [
            'name' => 'Pakistan',
            'currency' => 'PKR',
            'currencySymbol' => 'Rs',
            // "Rs 1,250".
            'currencySymbolSpace' => true,
            // Owner decision 2026-10-06: whole rupees ("Rs 1,250"; stored values keep 2 decimals) and lakh
            // grouping ("1,25,000", plain numbers too). 'en-PK' groups in thousands (CLDR, PHP intl and Node
            // alike), so the lakh grouping is done by MoneyFormat / lib/country.ts themselves.
            'displayDecimals' => 0,
            'grouping' => 'lakh',
            'numberLocale' => 'en-PK',
            'dateLocale' => 'en-PK',
            'timezone' => 'Asia/Karachi',
            'taxName' => 'GST',
            'taxIds' => [
                // Provisional patterns; phase P3 settles the forms.
                'ntn' => [
                    'label' => 'NTN',
                    'pattern' => '/^\d{7}-?\d$/',
                    'example' => '1234567-8',
                ],
                'strn' => [
                    'label' => 'STRN',
                    'pattern' => '/^\d{13}$/',
                    'example' => '1234567890123',
                ],
                'companyNumber' => [
                    'label' => 'SECP registration number',
                    'pattern' => '/^\d{7}$/',
                    'example' => '0123456',
                ],
            ],
            'address' => [
                'postcodeLabel' => 'Postal code',
                'postcodeRequired' => false,
                'postcodePattern' => '/^\d{5}$/',
                'postcodeExample' => '54000',
                'cityRequired' => true,
            ],
            'phone' => [
                // A mobile: 03XX XXXXXXX, spaces or a dash allowed after the network code.
                'pattern' => '/^03\d{2}[\s-]?\d{7}$/',
                'example' => '0300 1234567',
            ],
            'billing' => [
                // Invoices paid by hand (bank transfer, JazzCash, Easypaisa, cash), recorded by an admin (phase P5).
                'collection' => 'manual',
                // Owner 2026-10-06. jazzCash / easypaisa join PaymentMethod in phase P5.
                'manualMethods' => ['bankTransfer', 'jazzCash', 'easypaisa', 'cash'],
            ],
            'features' => [
                'vatReturn' => false,
                'fbr' => false,
            ],
        ],
    ],
];
