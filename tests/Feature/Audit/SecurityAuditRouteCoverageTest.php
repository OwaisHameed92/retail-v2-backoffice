<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Security audit 2026-10-11 (docs/audit/2026-10-11-security.md): route coverage. These tests walk EVERY registered
 * route, so a new route without the right middleware fails here. They pass on main today (no gap); the gaps found by
 * the audit are proved in SecurityAuditTest.php.
 */

const AUDIT_FAKE_ULID = '01JAAAAAAAAAAAAAAAAAAAAAAA';

/** Routes open to guests on purpose (each checked by hand in the audit doc). */
function auditPublicUris(): array
{
    return [
        '/', 'up', 'trial', 'legal/{page}', 'login', 'forgot-password', 'reset-password', 'reset-password/{token}',
        'admin/login', 'app/invitations/{invitation}/{token}', 'app/alerts/unsubscribe/{company}/{user}/{type}',
        'direct-debit/{company}/setup', 'direct-debit/{company}/done', 'webhooks/gocardless',
    ];
}

/** @return list<LaravelRoute> */
function auditRoutes(string $prefix): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $r) => $r->uri() === $prefix || str_starts_with($r->uri(), $prefix.'/'))
        ->reject(fn (LaravelRoute $r) => in_array($r->uri(), auditPublicUris(), true))
        ->values()->all();
}

/** A request URI with every parameter filled: a real company id where given, a valid but unknown id elsewhere. */
function auditUri(LaravelRoute $route, ?string $companyId = null): string
{
    return '/'.preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($route, $companyId) {
        $pattern = $route->wheres[$m[1]] ?? null;

        if ($m[1] === 'company' && $companyId !== null) {
            return $companyId;
        }

        foreach ([AUDIT_FAKE_ULID, '999999', 'welcome-tenant'] as $candidate) {
            if ($pattern === null || preg_match('#^'.$pattern.'$#', $candidate) === 1) {
                return $candidate;
            }
        }

        return explode('|', trim($pattern, '()'))[0]; // a whereIn list: its first value
    }, $route->uri());
}

function auditMethod(LaravelRoute $route): string
{
    return collect($route->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))->first() ?? 'GET';
}

/** @return list<string> */
function auditMiddleware(LaravelRoute $route): array
{
    return array_values(array_filter($route->gatherMiddleware(), 'is_string'));
}

test('every /app route needs a signed-in member of a business, and all but a few need a role ability', function () {
    $noAbility = ['app', 'app/branch/switch', 'app/company/switch', 'app/notifications', 'app/notifications/read', 'app/settings/notifications'];

    foreach (auditRoutes('app') as $route) {
        $mw = implode(' ', auditMiddleware($route));
        expect(str_contains($mw, 'auth:web') && str_contains($mw, ' company ') && str_contains($mw, 'two-factor:web'))->toBeTrue($route->uri());

        if (! in_array($route->uri(), $noAbility, true)) {
            expect(str_contains($mw, 'company.can:'))->toBeTrue($route->uri());
        }
    }
});

test('every /admin route needs an active admin, and all but the own-account pages need a can: ability', function () {
    $noCan = ['admin', 'admin/logout', 'admin/impersonation/stop', 'admin/licences/email-keys', 'admin/security',
        'admin/security/recovery-codes', 'admin/two-factor/challenge', 'admin/two-factor/setup'];

    foreach (auditRoutes('admin') as $route) {
        $mw = implode(' ', auditMiddleware($route));
        expect(str_contains($mw, 'auth:admin') && str_contains($mw, 'AdminIsActive'))->toBeTrue($route->uri());

        if (! in_array($route->uri(), $noCan, true)) {
            expect(str_contains($mw, 'can:'))->toBeTrue($route->uri());
        }
    }
});

test('a guest is sent to the right sign-in page from every portal, settings and admin route', function () {
    $this->withoutVite();

    foreach (['app', 'settings', 'admin'] as $prefix) {
        foreach (auditRoutes($prefix) as $route) {
            $response = $this->call(auditMethod($route), auditUri($route));
            $label = auditMethod($route).' '.$route->uri().' → '.$response->getStatusCode();

            expect($response->getStatusCode())->toBe(302, $label)
                ->and($response->headers->get('Location'))->toBe(route($prefix === 'admin' ? 'admin.login' : 'login'), $label);
        }
    }
});

test('a signed-in customer (business owner) cannot open any admin route', function () {
    $this->withoutVite();
    $company = Company::factory()->create();
    $owner = User::factory()->create();
    $company->users()->attach($owner->id, ['role' => CompanyRole::Owner->value, 'is_active' => true]);

    foreach (auditRoutes('admin') as $route) {
        $response = $this->actingAs($owner)->call(auditMethod($route), auditUri($route, $company->id));
        $label = auditMethod($route).' '.$route->uri().' → '.$response->getStatusCode();

        expect($response->getStatusCode())->toBe(302, $label)->and($response->headers->get('Location'))->toBe(route('admin.login'), $label);
    }
});

test('a Staff member gets 403 (or 404 for a country-only page) on every portal route their role lacks', function () {
    $this->withoutVite()->withoutMiddleware(ThrottleRequests::class);
    $company = Company::factory()->create();
    $staff = User::factory()->create();
    $company->users()->attach($staff->id, ['role' => CompanyRole::Staff->value, 'is_active' => true]);
    $checked = 0;

    foreach (auditRoutes('app') as $route) {
        $abilities = collect(auditMiddleware($route))->filter(fn ($m) => str_starts_with($m, 'company.can:'))
            ->map(fn ($m) => Str::after($m, 'company.can:'));

        if ($abilities->every(fn ($a) => CompanyRole::Staff->can($a))) {
            continue;
        }

        $response = $this->actingAs($staff)->call(auditMethod($route), auditUri($route));
        $label = auditMethod($route).' '.$route->uri().' → '.$response->getStatusCode();
        expect($response->getStatusCode())->toBeIn([403, 404], $label);
        $checked++;
    }

    expect($checked)->toBeGreaterThan(200);
});

test('every admin role gets 403 (or 404 for an unknown id) on each ability route its role lacks', function () {
    $this->withoutVite()->withoutMiddleware(ThrottleRequests::class);
    $company = Company::factory()->create();
    $checked = 0;

    foreach ([AdminRole::Sales, AdminRole::Support, AdminRole::Accounts] as $role) {
        $admin = Admin::factory()->role($role)->create();

        foreach (auditRoutes('admin') as $route) {
            $abilities = collect(auditMiddleware($route))->filter(fn ($m) => str_starts_with($m, 'can:'))
                ->map(fn ($m) => Str::after($m, 'can:'))->filter(fn ($a) => ! str_contains($a, ','));

            if ($abilities->isEmpty() || $abilities->every(fn ($a) => $role->can($a))) {
                continue;
            }

            $response = $this->actingAs($admin, 'admin')->call(auditMethod($route), auditUri($route, $company->id));
            $label = $role->value.' '.auditMethod($route).' '.$route->uri().' → '.$response->getStatusCode();
            expect($response->getStatusCode())->toBeIn([403, 404], $label);
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(100);
});
