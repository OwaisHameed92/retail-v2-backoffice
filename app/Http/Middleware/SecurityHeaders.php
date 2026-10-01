<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every web response (security review M4, config/security.php):
 *
 * - `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, a `Permissions-Policy`
 *   that switches off camera, microphone, geolocation, payment and USB;
 * - no framing by other sites (`X-Frame-Options: SAMEORIGIN` + CSP `frame-ancestors 'self'`; the admin email preview
 *   frames our own pages);
 * - an HTML page gets a Content-Security-Policy: scripts only from us (plus the Turnstile widget) or carrying this
 *   request's nonce (Vite tags, Ziggy's `@routes`, the theme script in app.blade.php); styles from us and inline
 *   (Radix and Recharts set inline styles); the Vite dev server is allowed while `npm run dev` runs (public/hot).
 *   Downloads and PDFs get no CSP (the browser's PDF viewer);
 * - `Strict-Transport-Security` on HTTPS requests only.
 */
class SecurityHeaders
{
    public function __construct(private readonly Vite $vite) {}

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = config('security.csp.enabled') ? $this->vite->useCspNonce() : null;

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        if ($nonce !== null && str_contains(strtolower((string) $headers->get('Content-Type', 'text/html')), 'text/html')) {
            $name = config('security.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $headers->set($name, $this->policy($nonce));
        }

        $maxAge = (int) config('security.hsts.max_age', 0);

        if ($request->isSecure() && $maxAge > 0) {
            $headers->set('Strict-Transport-Security', 'max-age='.$maxAge.(config('security.hsts.include_subdomains') ? '; includeSubDomains' : ''));
        }

        return $response;
    }

    public function policy(string $nonce): string
    {
        $dev = $this->devServer();
        $hosts = fn (string $key): array => array_values(array_filter((array) config("security.csp.{$key}", []), 'is_string'));

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$hosts('script_hosts'), ...$dev],
            'style-src' => ["'self'", "'unsafe-inline'", ...$hosts('font_hosts'), ...$dev],
            'font-src' => ["'self'", 'data:', ...$hosts('font_hosts'), ...$dev],
            'img-src' => ["'self'", 'data:', 'blob:', 'https:', ...$dev],
            'connect-src' => ["'self'", ...$hosts('connect_hosts'), ...$dev, ...array_map(fn (string $origin) => (string) preg_replace('#^http#', 'ws', $origin), $dev)],
            'frame-src' => ["'self'", ...$hosts('frame_hosts')],
            'worker-src' => ["'self'", 'blob:'],
        ];

        return implode('; ', array_map(fn (string $name, array $sources) => $name.' '.implode(' ', array_unique($sources)), array_keys($directives), $directives));
    }

    /**
     * The Vite dev server's origin while `npm run dev` runs (its URL is in public/hot), else none.
     *
     * @return list<string>
     */
    private function devServer(): array
    {
        if (! $this->vite->isRunningHot()) {
            return [];
        }

        $url = trim((string) @file_get_contents($this->vite->hotFile()));
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        $host = str_contains($parts['host'], ':') && ! str_starts_with($parts['host'], '[') ? '['.$parts['host'].']' : $parts['host'];

        return [$parts['scheme'].'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '')];
    }
}
