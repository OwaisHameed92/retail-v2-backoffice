<?php

/*
 * Pharmacy (W4): the dm+d release server (PORTAL-CHANGES-pharmacy.md item 12, docs/verticals-0.1.85.md "dm+d release
 * server"). Off by default: with PHARMACY_DMD off `GET /api/v1/pharmacy/dmd/latest` answers 501 dmd.not_available and
 * the weekly fetch does nothing, so no shop sees a change.
 */
return [
    'dmd' => [
        'enabled' => (bool) env('PHARMACY_DMD', false),

        // Our own NHS TRUD API key (the owner's TRUD account, subscribed to item 24). A secret: never logged, never
        // shown (the admin card says only whether one is set). TRUD puts it in the URL path, so every message about a
        // TRUD call is redacted before it is logged or stored (DmdSwitch::redact()).
        'trud_key' => env('PHARMACY_DMD_TRUD_KEY'),

        'trud' => [
            // TRUD item 24: "NHSBSA dm+d".
            'item' => 24,
            'base_url' => 'https://isd.digital.nhs.uk/trud/api/v1',
            // The zip must come from one of these hosts (https only); TRUD may redirect from there.
            'download_hosts' => ['isd.digital.nhs.uk'],
            'connect_timeout' => 15,
            'timeout' => 30,
            // The weekly zip is 30-60 MB (plan for 150 MB).
            'download_timeout' => 900,
        ],

        // Where the zips are kept, each under {path}/{releaseId}/ with the Open Government Licence attribution.
        'disk' => env('PHARMACY_DMD_DISK', 'local'),
        'path' => 'pharmacy/dmd',

        // Kept: the newest release and those from the previous `keep_weeks` weeks (tills that read the manifest a
        // moment before a switch still find their zip).
        'keep_weeks' => 2,

        // The zip's signed link (no headers needed) lives this long after the manifest hands it out; at least 24 h.
        'link_hours' => 48,

        // https base of downloadUrl (default APP_URL with https).
        'public_url' => env('PHARMACY_DMD_PUBLIC_URL'),

        // Manifest calls per sync key per minute (on top of the sync key's own limit); zip downloads per IP.
        'per_minute' => 10,
        'downloads_per_minute' => 20,

        // Retry-After of 501 dmd.not_available.
        'not_available_retry_after' => 3600,

        'attribution' => 'Contains public sector information licensed under the Open Government Licence v3.0.',
    ],
];
