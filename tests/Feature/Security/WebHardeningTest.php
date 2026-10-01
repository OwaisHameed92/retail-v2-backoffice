<?php

use App\Domain\Accounts\Queries\VatReturnHelper;
use App\Domain\Shared\Support\CsvText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/*
 * Security review 7.3: M3 trusted proxies, M4 security headers and cookies, L2 CSV text, L6 passwords and auth
 * throttles, L7 no signed storage routes.
 */

/**
 * Loads a config file again with the given environment variables set (config files read env() when loaded).
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function configWithEnv(string $file, array $env): array
{
    $saved = [];

    foreach ($env as $name => $value) {
        $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        return require config_path($file);
    } finally {
        foreach ($saved as $name => [$env, $server]) {
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }

            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
    }
}

beforeEach(function () {
    Route::middleware('web')->get('/_security/ip', fn (Request $request) => $request->ip());
    Route::middleware('web')->get('/_security/pdf', fn () => response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']));
});

test('M3: no proxy is trusted by default, so X-Forwarded-For cannot spoof the client IP', function () {
    expect(config('trustedproxy.proxies'))->toBeNull();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->withHeaders(['X-Forwarded-For' => '203.0.113.7'])
        ->get('/_security/ip')->assertOk()->assertSeeText('198.51.100.20');
});

test('M3: a configured proxy may pass the client IP on; anyone else may not', function () {
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->withHeaders(['X-Forwarded-For' => '203.0.113.7'])
        ->get('/_security/ip')->assertSeeText('203.0.113.7');
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->withHeaders(['X-Forwarded-For' => '203.0.113.7'])
        ->get('/_security/ip')->assertSeeText('198.51.100.20');
});

test('M3: TRUSTED_PROXIES takes IPs, CIDRs, "cloudflare" or "*"', function () {
    $cloudflare = configWithEnv('trustedproxy.php', ['TRUSTED_PROXIES' => 'cloudflare, 10.0.0.1'])['proxies'];

    expect(configWithEnv('trustedproxy.php', ['TRUSTED_PROXIES' => ''])['proxies'])->toBeNull()
        ->and($cloudflare)->toContain('173.245.48.0/20', '2400:cb00::/32', '10.0.0.1')
        ->and(configWithEnv('trustedproxy.php', ['TRUSTED_PROXIES' => '*'])['proxies'])->toBe('*');
});

test('M4: web pages carry the security headers and a nonce-based CSP that the page\'s inline scripts use', function () {
    $response = $this->get('/login')->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
        ->assertHeaderMissing('Strict-Transport-Security');

    $csp = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $csp, $nonce);

    expect($csp)->toContain("frame-ancestors 'self'", "object-src 'none'", "base-uri 'self'", 'https://challenges.cloudflare.com')
        ->not->toContain("'unsafe-eval'")
        ->and($nonce[1] ?? '')->not->toBe('')
        ->and(substr_count((string) $response->getContent(), 'nonce="'.($nonce[1] ?? '-').'"'))->toBeGreaterThanOrEqual(2);
});

test('M4: HSTS only over HTTPS; downloads get no CSP; report-only mode', function () {
    $this->get('https://localhost/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    $this->get('/_security/pdf')->assertHeaderMissing('Content-Security-Policy')->assertHeader('X-Content-Type-Options', 'nosniff');

    config(['security.csp.report_only' => true]);
    $this->get('/login')->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('Content-Security-Policy-Report-Only');
});

test('M4: session cookies are HTTPS-only in production or with an https APP_URL, unless set explicitly', function () {
    expect(configWithEnv('session.php', ['APP_ENV' => 'production', 'APP_URL' => 'http://x.test'])['secure'])->toBeTrue()
        ->and(configWithEnv('session.php', ['APP_ENV' => 'local', 'APP_URL' => 'https://portal.test'])['secure'])->toBeTrue()
        ->and(configWithEnv('session.php', ['APP_ENV' => 'local', 'APP_URL' => 'http://localhost'])['secure'])->toBeFalse()
        ->and(configWithEnv('session.php', ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false'])['secure'])->toBeFalse()
        ->and(config('session.same_site'))->toBe('lax')
        ->and(config('session.http_only'))->toBeTrue();
});

test('L2: typed text that Excel would run as a formula is quoted', function () {
    expect(CsvText::safe('=HYPERLINK("http://x")'))->toBe('\'=HYPERLINK("http://x")')
        ->and(CsvText::safe('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(CsvText::safe('Khan Mini Mart'))->toBe('Khan Mini Mart')
        ->and(CsvText::safe(''))->toBe('');

    $vat = ['quarter' => ['from' => '2026-07-01', 'to' => '2026-09-30'], 'boxes' => [], 'position' => 'pay'];
    expect(array_slice(VatReturnHelper::csv($vat, '=cmd|"/c calc"!A1', '+Leeds'), 1, 2))->toBe([
        ['Business', '\'=cmd|"/c calc"!A1'], ['Shop', "'+Leeds"],
    ]);
});

test('L6: customer passwords need at least 10 characters', function () {
    expect(Validator::make(['p' => 'abc12345'], ['p' => Password::defaults()])->fails())->toBeTrue()
        ->and(Validator::make(['p' => 'abcd123456'], ['p' => Password::defaults()])->passes())->toBeTrue();
});

test('L6: forgot-password, reset-password and confirm-password are throttled', function () {
    foreach (range(1, 6) as $i) {
        $this->post('/forgot-password', ['email' => "nobody{$i}@shop.test"])->assertStatus(302);
    }
    $this->post('/forgot-password', ['email' => 'nobody@shop.test'])->assertStatus(429);

    $routes = Route::getRoutes();
    expect($routes->getByName('password.store')?->gatherMiddleware())->toContain('throttle:6,1')
        ->and($routes->match(Request::create('/confirm-password', 'POST'))->gatherMiddleware())->toContain('throttle:6,1');
});

test('L7: the signed storage routes are not registered', function () {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local.upload'))->toBeFalse();
});
