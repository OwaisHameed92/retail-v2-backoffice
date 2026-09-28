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

return [

    // Who the invoices are from. Shown on every invoice and PDF.
    'seller' => [
        'name' => env('BILLING_SELLER_NAME', 'Switch & Save'),
        'legal_name' => env('BILLING_SELLER_LEGAL_NAME', 'Switch & Save Ltd'),
        'address' => env('BILLING_SELLER_ADDRESS', ''),
        'company_number' => env('BILLING_SELLER_COMPANY_NUMBER', ''),
        'email' => env('BILLING_SELLER_EMAIL') ?: (env('SSPOS_SUPPORT_EMAIL') ?: 'support@switchandsave.co.uk'),
        'phone' => (string) env('BILLING_SELLER_PHONE', env('SSPOS_SUPPORT_PHONE', '')),
    ],

    // VAT. Turn off while we are not VAT registered: invoices then carry no VAT at all. A company can also be
    // billed without VAT from its billing settings.
    'vat' => [
        'enabled' => (bool) env('BILLING_VAT_ENABLED', true),
        'number' => env('BILLING_VAT_NUMBER', ''),
        // Percent, as a decimal string.
        'rate' => (string) env('BILLING_VAT_RATE', '20.00'),
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
    'suspend_after_days' => (int) env('BILLING_SUSPEND_AFTER_DAYS', 14),

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

    // GoCardless Direct Debit (module 1.12). Keys are in config/services.php (GOCARDLESS_*).
    'direct_debit' => [
        // Days a Direct Debit customer keeps trading after the trial ends (or after the mandate is cancelled)
        // without a working mandate: then suspended (trial) or marked overdue (lost mandate).
        'mandate_grace_days' => (int) env('BILLING_MANDATE_GRACE_DAYS', 3),
        // A second "your Direct Debit failed" email this many days after a failed payment still unpaid.
        'dunning_reminder_days' => (int) env('BILLING_DD_REMINDER_DAYS', 5),
        // How long the Direct Debit setup link in the email works.
        'setup_link_days' => 14,
        // Most setup fee instalments.
        'max_instalments' => 12,
        // How far back the daily reconcile compares payments with GoCardless.
        'reconcile_days' => 45,
    ],

];
