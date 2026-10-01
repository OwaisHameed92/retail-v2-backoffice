<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies (security review M3)
|--------------------------------------------------------------------------
|
| Read by Laravel's TrustProxies middleware (global). Only a request from one of these addresses may set the client
| IP, scheme and host with X-Forwarded-* headers, so per-IP rate limits (licence activate, trial form, logins) see
| the real client behind a load balancer or Cloudflare, and nobody else can spoof their IP.
|
| TRUSTED_PROXIES, comma separated:
| - empty (default): trust no proxy; $request->ip() is the TCP peer. Right when PHP faces the internet directly.
| - IPs or CIDRs of your own load balancer / reverse proxy, e.g. "10.0.0.0/8,127.0.0.1".
| - "cloudflare": Cloudflare's published edge ranges (below; refresh from https://www.cloudflare.com/ips/).
| - "*": trust whoever connects. Only when the server is reachable solely through the proxy (firewalled), else
|   any client can spoof X-Forwarded-For.
|
*/

$cloudflare = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
    '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
    '2c0f:f248::/32',
];

$entries = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
$proxies = [];

foreach ($entries as $entry) {
    if (in_array($entry, ['*', '**'], true)) {
        $proxies = $entry;

        break;
    }

    array_push($proxies, ...(strtolower($entry) === 'cloudflare' ? $cloudflare : [$entry]));
}

return [
    'proxies' => $proxies === [] ? null : $proxies,
    'cloudflare' => $cloudflare,
];
