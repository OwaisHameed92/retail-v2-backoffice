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
            // The currency in words, for text such as "Amounts are in pounds (GBP)" and the AI prompts (phase P2).
            'currencyName' => 'pounds',
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
                // TenantRules::POSTCODE_PATTERN. Required on the profile: a UK shop always has one. Phase P4: the profile
                // can only make a form's postcode optional, never newly required, so each GB form keeps its own
                // required-ness (the tenant, shop and lead forms optional, the trial form required).
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
                // The country calling code (phase P4): "+44 7700 900123", "0044 …" and "07700 …" are one number
                // for duplicate checks (PhoneDigits) and the AI assistant's phone scrub.
                'dialCode' => '44',
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
            'currencyName' => 'rupees',
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
                // Phase P3, lenient on purpose (FBR formats vary): TenantRules strips spaces first, and STRN dashes too.
                // NTN (stored in `vat_number`): 7 digits with an optional check digit ("1234567", "1234567-8"), or a
                // sole trader's 13-digit CNIC ("35202-1234567-1", dashes optional).
                'ntn' => [
                    'label' => 'NTN',
                    'pattern' => '/^(\d{7}(-?\d)?|\d{5}-?\d{7}-?\d)$/',
                    'example' => '1234567-8',
                ],
                // STRN (sales tax registration, column `strn`): 13 digits.
                'strn' => [
                    'label' => 'STRN',
                    'pattern' => '/^\d{13}$/',
                    'example' => '1234567890123',
                ],
                // SECP company registration (`company_number`): 7 digits.
                'companyNumber' => [
                    'label' => 'SECP registration number',
                    'pattern' => '/^\d{7}$/',
                    'example' => '0123456',
                ],
            ],
            'address' => [
                // Phase P4: optional everywhere (the trial form too), 5 digits when given; the town (city) is
                // required on forms that have one as soon as an address or postal code is entered.
                'postcodeLabel' => 'Postal code',
                'postcodeRequired' => false,
                'postcodePattern' => '/^\d{5}$/',
                'postcodeExample' => '54000',
                'cityRequired' => true,
            ],
            'phone' => [
                // Phase P4: a mobile ("0300 1234567", "+92 300 1234567", "0092 300 1234567") or a landline
                // ("042 35761234", "+92 42 35761234"): 0, +92 or 0092, then 9 or 10 digits not starting with 0;
                // spaces and dashes allowed between digits.
                'pattern' => '/^(?:\+92|0092|0)[\s-]?[1-9](?:[\s-]?\d){8,9}$/',
                'example' => '0300 1234567',
                'dialCode' => '92',
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
