<?php

/*
|--------------------------------------------------------------------------
| Switch & Save business details
|--------------------------------------------------------------------------
|
| Contact details and links shown to customers, mostly in emails (module 1.7). No secrets here.
|
*/

return [

    // Shown in every email footer and in "how to get help" copy.
    'support_email' => env('SSPOS_SUPPORT_EMAIL') ?: 'support@switchandsave.co.uk',

    // Optional. Leave empty to hide the phone number.
    'support_phone' => (string) env('SSPOS_SUPPORT_PHONE', ''),

    // Where staff alerts (new trial requests) are sent. Defaults to the support address.
    'staff_email' => env('SSPOS_STAFF_EMAIL') ?: (env('SSPOS_SUPPORT_EMAIL') ?: 'support@switchandsave.co.uk'),

    // New lead alerts (module 1.6): comma-separated list, e.g. "sales@…,owner@…". Empty = staff_email only.
    'lead_alert_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('SSPOS_LEAD_ALERT_EMAILS', ''))))),

    // Download page for the SSPOS EPOS (till) installer.
    'epos_download_url' => env('SSPOS_EPOS_DOWNLOAD_URL') ?: 'https://switchandsave.co.uk/download',

    // Customer portal home. The portal login is at {portal_url}/login.
    'portal_url' => rtrim((string) (env('SSPOS_PORTAL_URL') ?: env('APP_URL', 'http://localhost')), '/'),

    // Public trial form API (module 1.10): browser origins allowed to post to /api/v1/public/*, comma-separated,
    // e.g. "https://switchandsave.co.uk,https://www.switchandsave.co.uk". Our own /trial page is always allowed.
    'public_form_origins' => array_values(array_filter(array_map(
        fn (string $origin) => rtrim(trim($origin), '/'),
        explode(',', (string) env('PUBLIC_FORM_ORIGINS', '')),
    ))),

    // Email log retention (months). Older rows are pruned daily by model:prune.
    'email_log_retention_months' => (int) env('SSPOS_EMAIL_LOG_RETENTION_MONTHS', 12),

];
