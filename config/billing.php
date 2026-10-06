<?php

/*
|--------------------------------------------------------------------------
| Cash billing (module 1.8)
|--------------------------------------------------------------------------
|
| Invoices per tenant, payments recorded by staff (cash, bank transfer, other), overdue handling and the
| daily `billing:run`. Money is in pounds (decimal strings), never floats. See docs/DECISIONS.md.
|
*/

use App\Domain\Shared\Country\Country;

// Pakistan plan P5 (owner 2026-10-06): the UK defaults below apply on GB only. Off GB an unset key is empty and its
// line is hidden (the instance shows only what its .env sets, never the UK company).
$uk = Country::fromConfig()->is(Country::DEFAULT);

return [

    // Who the invoices are from. Shown on every invoice and PDF.
    'seller' => [
        'name' => env('BILLING_SELLER_NAME', 'Switch & Save'),
        'legal_name' => env('BILLING_SELLER_LEGAL_NAME', $uk ? 'Switch & Save Ltd' : ''),
        'address' => env('BILLING_SELLER_ADDRESS', ''),
        'company_number' => env('BILLING_SELLER_COMPANY_NUMBER', ''),
        // Where the company is registered, for "Registered in …, company no. …" (phase P6). Empty = the country
        // profile's place: "England and Wales" on GB, "Pakistan" on PK.
        'registered_in' => (string) env('BILLING_SELLER_REGISTERED_IN', ''),
        'email' => env('BILLING_SELLER_EMAIL') ?: (env('SSPOS_SUPPORT_EMAIL') ?: ($uk ? 'support@switchandsave.co.uk' : '')),
        'phone' => (string) env('BILLING_SELLER_PHONE', env('SSPOS_SUPPORT_PHONE', '')),
        // Pakistan plan P5: our own NTN and STRN, printed (labelled) on PK invoices when set; empty = hidden. The NTN
        // falls back to BILLING_VAT_NUMBER (the NTN slot since P3). Never used on GB.
        'ntn' => (string) env('BILLING_SELLER_NTN', ''),
        'strn' => (string) env('BILLING_SELLER_STRN', ''),
    ],

    // VAT. Turn off while we are not VAT registered: invoices then carry no VAT at all. A company can also be
    // billed without VAT from its billing settings.
    'vat' => [
        // Off GB (PK): off unless BILLING_VAT_ENABLED says otherwise (GST registration is set per instance).
        'enabled' => (bool) env('BILLING_VAT_ENABLED', $uk),
        'number' => env('BILLING_VAT_NUMBER', ''),
        // Percent, as a decimal string. Off GB: 0 unless BILLING_VAT_RATE is set.
        'rate' => (string) env('BILLING_VAT_RATE', $uk ? '20.00' : '0.00'),
    ],

    // Shown on invoices under "How to pay". Leave empty to hide the bank details.
    'bank' => [
        'account_name' => env('BILLING_BANK_ACCOUNT_NAME', ''),
        'sort_code' => env('BILLING_BANK_SORT_CODE', ''),
        'account_number' => env('BILLING_BANK_ACCOUNT_NUMBER', ''),
    ],

    // Default payment terms for new billing settings (days after the issue date).
    'payment_terms_days' => (int) env('BILLING_PAYMENT_TERMS_DAYS', 7),

    // billing:run suspends a company when an invoice is still unpaid this many days after its due date.
    'suspend_after_days' => (int) env('BILLING_SUSPEND_AFTER_DAYS', 7),

    'generate' => [
        // billing:run creates the next invoice when a company's licences end within this many days.
        'days_before' => (int) env('BILLING_GENERATE_DAYS_BEFORE', 7),
        // true: issue (and email) generated invoices straight away. false: leave them as drafts to review.
        'auto_issue' => (bool) env('BILLING_AUTO_ISSUE', false),
        // Charge part of the period for tills whose paid/trial time runs into it. Off by default.
        'prorate' => (bool) env('BILLING_PRORATE', false),
    ],

    // Use a company's unallocated credit on a new invoice as soon as it is issued.
    'apply_credit_on_issue' => true,

    'trial' => [
        // TrialReminderMail goes this many days before a company's first trial licence ends.
        'reminder_days_before' => 2,
        // TrialEndedMail is only sent for trials that ended within this many days (no mail for old trials).
        'ended_window_days' => 3,
    ],

    // Setup-only plans (owner rule 2026-10-05): once the setup fee is paid in full, every live till gets a full
    // licence this many years ahead, topped up by billing:run whenever less than `renew_below_years` are left.
    'setup_only' => [
        'years' => 10,
        'renew_below_years' => 9,
    ],

    // GoCardless Direct Debit (module 1.12). Keys are in config/services.php (GOCARDLESS_*).
    'direct_debit' => [
        // Days a Direct Debit customer keeps trading after its mandate is cancelled, fails or expires without a new
        // one: then suspended (tills lock) until a new mandate exists (owner rule 2026-10-05).
        'mandate_grace_days' => (int) env('BILLING_MANDATE_GRACE_DAYS', 3),
        // The "set up your Direct Debit" reminder goes once when fewer than this many hours are left.
        'mandate_reminder_hours' => 48,
        // Module 1.13: a new Direct Debit customer sets it up in the portal within this many days of onboarding,
        // else billing:run suspends the business (tills lock) until the mandate exists. Not when nothing recurs.
        'mandate_deadline_days' => (int) env('BILLING_MANDATE_DEADLINE_DAYS', 3),
        // A second "your Direct Debit failed" email this many days after a failed payment still unpaid.
        'dunning_reminder_days' => (int) env('BILLING_DD_REMINDER_DAYS', 5),
        // How long the Direct Debit setup link in the email works.
        'setup_link_days' => 14,
        // Most setup fee instalments.
        'max_instalments' => 12,
        // How far back the daily reconcile compares payments with GoCardless.
        'reconcile_days' => 45,
    ],

    // Manual collection (Pakistan plan P5): only where the country profile's `billing.collection` is "manual" (PK).
    // Every monthly or yearly period is an invoice paid by hand (bank transfer, JazzCash, Easypaisa, cash) and
    // recorded by an admin; GoCardless is never used. Unused on GB (Direct Debit).
    'manual' => [
        // A period invoice is due this many days after it is issued. billing:run issues it
        // `generate.days_before` (7) days before the period starts, so it is normally due on the period start.
        'due_days' => (int) env('BILLING_MANUAL_DUE_DAYS', 7),
        // Reminder emails: this many days before the due date, on the due date, and this many days after it.
        'remind_before_days' => (int) env('BILLING_MANUAL_REMIND_BEFORE_DAYS', 3),
        'remind_after_days' => (int) env('BILLING_MANUAL_REMIND_AFTER_DAYS', 3),
        // How to pay, on invoices, emails and the portal Billing page. Empty values are hidden.
        'pay' => [
            'bank_name' => (string) env('BILLING_PAY_BANK_NAME', ''),
            'bank_account_title' => (string) env('BILLING_PAY_BANK_ACCOUNT_TITLE', ''),
            'bank_iban' => (string) env('BILLING_PAY_BANK_IBAN', ''),
            // Mobile account numbers (or till ids) customers send JazzCash / Easypaisa payments to.
            'jazzcash' => (string) env('BILLING_PAY_JAZZCASH', ''),
            'easypaisa' => (string) env('BILLING_PAY_EASYPAISA', ''),
        ],
    ],

];
