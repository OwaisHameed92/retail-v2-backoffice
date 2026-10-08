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
                // Phase P10 (owner 2026-10-07): UK till modules the portal shows (App\Domain\Shared\Country\CountryModules).
                // Off = hidden in the portal (pages, form fields, settings, portal actions) and their routes 404; till
                // data still syncs and is stored exactly as before. A module missing here counts as on.
                'depositReturn' => true,
                'lottery' => true,
                'alcoholLicensing' => true,
                'hfss' => true,
                'vapingDuty' => true,
                'ukStarterSet' => true,
                'pharmacy' => true,
            ],
            'legal' => [
                // Phase P6: where our own company is registered, for the seller line on our invoices ("Registered in
                // England and Wales, company no. …"). `BILLING_SELLER_REGISTERED_IN` overrides it per instance.
                'registeredIn' => 'England and Wales',
            ],
            // Phase P9: the till's Branch `nation` values offered on the shop forms (App\Domain\Tenancy\Enums\Nation,
            // in this order). Empty = the field is hidden and a shop keeps the column default ("england").
            'nations' => ['england', 'scotland', 'wales', 'northernIreland'],
            // Phase P9: UK sample places in examples and previews ("e.g. Leeds", "LDS-01-000482") and what replaces them
            // on this profile. GB has none: its text is shown as written.
            'samplePlaces' => [],
            // Till-facing profile values (App\Domain\Shared\Country\TillProfile; Pak POS pack 2026-10-07). GB keeps every
            // reply, token and pull exactly as before: no licence country (the UK till 0.1.60 does not read one), the
            // `nations` above, every age rule, product prices as the forms always took them, versions compared as they are.
            'till' => [
                // Licence `country` (contract §17.18): null = not sent. Set 'GB' here when the UK tills should get it.
                'licenceCountry' => null,
                // The till app's name on portal pages and alerts.
                'appName' => 'SSPOS',
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
                // Phase P10: no deposit return scheme, UK-style shop lottery, UK alcohol licensing, HFSS or vaping duty in
                // Pakistan; the master catalogue starter set is UK products; pharmacy waits for a DRAP version.
                'depositReturn' => false,
                'lottery' => false,
                'alcoholLicensing' => false,
                'hfss' => false,
                'vapingDuty' => false,
                'ukStarterSet' => false,
                'pharmacy' => false,
            ],
            'legal' => [
                'registeredIn' => 'Pakistan',
            ],
            // Phase P9: the till contract has no Pakistani value for Branch `nation` (England, Scotland, Wales, Northern
            // Ireland only): the field is hidden and a shop keeps the column default until EPOS answers.
            'nations' => [],
            // Longest first where one contains another ("Leeds Road" before "Leeds").
            'samplePlaces' => [
                'Leeds Road' => 'Mall Road',
                'Leeds' => 'Lahore',
                'Bradford' => 'Karachi',
                'LDS' => 'LHR',
                'BFD' => 'KHI',
            ],
            // Pak POS pack 2026-10-07 (docs/contracts/pak-pos-2026-10-07, DECISIONS "Pak POS pack 2026-10-07").
            'till' => [
                // §17.18: "PK" in the signed token payload and top level in licence/activate and licence/validate.
                'licenceCountry' => 'PK',
                'appName' => 'Pak POS',
                // Pak POS versions are their own line from 1.0.0, cut from SSPOS 3 0.1.53: against a gate written in
                // SSPOS 3's 0.x numbers a Pak POS version counts as this baseline (TillProfile::compareAppVersion).
                'ssposBaseline' => '0.1.53',
                // The first Pak POS version: the `minimumAppVersion` sent while the configured one is in 0.x numbers.
                'firstAppVersion' => '1.0.0',
                // Branch `nation` of every Pakistan shop (owner's answer): stored and pulled as written.
                'branchNation' => 'Pakistan',
                // One age rule, eighteen: the portal's pickers offer only these (stored till values are kept).
                'ageRules' => ['none', 'over18'],
                // A product's price may be up to 9,999,999.99 (7 whole digits); GB keeps its forms' 8.
                'priceDigits' => 7,
                // The Till settings page (App\Domain\ShopSettings\Support\CountrySettings). GB has none: its catalogue is
                // shown exactly as written.
                'settings' => [
                    // Never shown or read by the Pak POS till; a value already stored stays as it is (`*` = any ending).
                    'hidden' => [
                        'till.keypad_price_in_pence', 'compliance.mup_*', 'compliance.challenge25_*', 'compliance.nicotine_products_gated',
                        'compliance.energy_drink_age_gate', 'compliance.generational_*',
                    ],
                    // Shared settings only the Pak POS line has, section => key => definition (catalogue.php's shape).
                    'added' => [
                        'shop' => [
                            'shop.currency_symbol' => ['label' => 'Currency sign', 'type' => 'text', 'max' => 6, 'everyShopOnly' => true, 'help' => 'Written before every amount on the till, receipts, reports and labels, for example Rs. The amounts themselves do not change.'],
                        ],
                        'payments' => [
                            'payments.allow_wallets' => ['label' => 'Phone wallets', 'type' => 'bool', 'help' => 'JazzCash, Easypaisa and Raast QR on the till\'s payment screen. The customer pays from their phone; the transaction ID is printed as "Ref …".'],
                            'payments.quick_cash' => ['label' => 'Quick cash buttons', 'type' => 'text', 'max' => 60, 'help' => 'The amounts on the till\'s quick cash buttons, separated by commas.'],
                        ],
                        'accounts' => [
                            'messaging.whatsapp_country_code' => ['label' => 'WhatsApp country code', 'type' => 'int', 'min' => 1, 'max' => 999, 'help' => 'Put in front of customers\' local mobile numbers when the till sends WhatsApp messages.'],
                        ],
                    ],
                    // [label, help] in place of the catalogue's.
                    'labels' => [
                        'payments.round_cash_to_5p' => ['Round cash to the rupee', 'Cash totals are rounded to the nearest rupee. Card and wallet payments are never rounded.'],
                    ],
                    // The till's start values here (UPCOMING-CHANGES 2026-10-07, pak-pos): shown as the default of a setting
                    // on the page. Money settings start on rupee sums; nothing is pushed or stored.
                    'defaults' => [
                        'shop.currency_symbol' => 'Rs',
                        'payments.allow_wallets' => 'true',
                        'payments.round_cash_to_5p' => 'true',
                        'payments.quick_cash' => '100,500,1000,5000',
                        'messaging.whatsapp_country_code' => '92',
                        'till.manager_pin_discount_over' => '500.00',
                        'till.clear_cart_requires_pin_over' => '2000.00',
                        'till.refund_without_receipt_max' => '2000.00',
                        'till.bag_charge_amount' => '10.00',
                        'cash.default_float' => '5000.00',
                        'cash.variance_alert_over' => '500.00',
                        'cash.high_value_variance_threshold' => '1000.00',
                        'cash.safe_drop_prompt_over' => '50000.00',
                        'stock.take_recount_over_value' => '2000.00',
                        'stock.take_approval_over' => '10000.00',
                        'stock.adjust_manager_pin_over' => '5000.00',
                        'staff.discount_daily_cap' => '1000.00',
                        'staff.discount_weekly_cap' => '3000.00',
                        'loss_prevention.refund_amount_per_shift' => '10000.00',
                        'loss_prevention.discount_minimum_amount' => '2000.00',
                        'vouchers.min_issue_amount' => '500.00',
                    ],
                    // Money settings' highest values × this (rupee sums run about a hundred times larger than pounds).
                    'moneyMaxFactor' => 100,
                ],
            ],
        ],
    ],
];
