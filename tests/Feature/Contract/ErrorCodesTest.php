<?php

use Tests\Support\ContractReplyGuard;
use Tests\Support\ContractSchema;

/*
 * Module 2.6 (contract v1.4.1 §9, §17.11 rule 10, §17.12): every error code a till can get from us is in
 * licensing/samples/error-codes.json, with the same HTTP status. Statically (every code written in app/) and at
 * run time (ContractReplyGuard checks every till reply of every feature test).
 */

/** Files that serve other callers than the till: the public trial form and payment webhooks. */
const CONTRACT_NOT_TILL_API = [
    'app/Domain/Leads/', 'app/Http/Middleware/PublicFormCors.php', 'app/Http/Middleware/GuardPublicTrialRequests.php',
    'app/Http/Requests/Api/StoreTrialRequest.php', 'app/Http/Controllers/Webhooks/',
];

/** ApiExceptionRenderer's names for framework errors outside the till endpoints (api/* public form, unknown URLs). */
const CONTRACT_OUTSIDE_TILL_ENDPOINTS = ['auth.forbidden', 'request.not_found', 'request.method_not_allowed'];

/**
 * Every error code literal in app/, file => codes.
 *
 * @return array<string, list<string>>
 */
function errorCodesInApp(): array
{
    $patterns = [
        "/new (?:ApiException|self)\\(\\s*'([a-z_]+\\.[a-z_]+)'/",
        "/ApiException::(?:notFound|forbidden|conflict)\\(\\s*'([a-z_]+\\.[a-z_]+)'/",
        "/(?:ApiExceptionRenderer|self)::response\\(\\s*\\\$request,\\s*'([a-z_]+\\.[a-z_]+)'/",
        "/=> \\['([a-z_]+\\.[a-z_]+)', ApiErrorMessages::/",
    ];
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr((string) $file, strlen(base_path()) + 1);

        if (! str_ends_with($relative, '.php') || array_filter(CONTRACT_NOT_TILL_API, fn ($prefix) => str_starts_with($relative, $prefix)) !== []) {
            continue;
        }

        // Code only: a docblock example is not an emitted code.
        $source = implode('', array_map(
            fn ($token) => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token,
            token_get_all((string) file_get_contents((string) $file)),
        ));

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches);

            foreach ($matches[1] as $code) {
                $found[$relative][] = $code;
            }
        }
    }

    return $found;
}

test('every error code the portal emits to a till is in error-codes.json (static scan of app/)', function () {
    $found = errorCodesInApp();
    $all = array_unique(array_merge(...array_values($found)));
    $known = ContractReplyGuard::knownCodes();

    // The scan really finds the codes (sync, licence, framework, module 2.8).
    expect($all)->toContain('auth.invalid_key', 'key.already_used', 'device.not_found', 'row.invalid', 'server.busy', 'request.idempotency_mismatch', 'licence.ids_conflict', 'key.used_on_another_install', 'migrate.upload_closed');

    foreach ($found as $file => $codes) {
        foreach ($codes as $code) {
            $allowed = array_key_exists($code, $known)
                || ($file === 'app/Domain/Shared/Exceptions/ApiExceptionRenderer.php' && in_array($code, CONTRACT_OUTSIDE_TILL_ENDPOINTS, true));

            expect($allowed)->toBeTrue("{$file} emits {$code}, which is not in licensing/samples/error-codes.json");
        }
    }
});

test('error-codes.json is a list of unique contract codes with a status each', function () {
    $list = json_decode((string) file_get_contents(ContractSchema::dir('licensing/samples/error-codes.json')), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($list, 'code');

    expect($codes)->toHaveCount(count(array_unique($codes)))->toContain('request.invalid', 'auth.invalid_key', 'key.already_used');

    foreach ($list as $entry) {
        expect($entry['code'])->toMatch('/^[a-z]+(_[a-z]+)*\.[a-z]+(_[a-z]+)*$/')
            ->and($entry['status'])->toBeInt()->toBeGreaterThanOrEqual(400)
            ->and($entry)->toHaveKeys(['endpoints', 'meaning', 'tillDoes', 'retryable']);
    }
});

test('a code we emit that the contract does not list yet is recorded as pending in docs/DECISIONS.md', function () {
    $decisions = (string) file_get_contents(base_path('docs/DECISIONS.md'));

    foreach (array_keys(ContractReplyGuard::PENDING_CODES) as $code) {
        expect($decisions)->toMatch('/`'.preg_quote($code, '/').'`[^\n]*pending/i');
    }

    // Contract v1.4.1 answers (b): licence.ids_conflict is in error-codes.json now, no longer ours alone.
    expect(ContractReplyGuard::PENDING_CODES)->not->toHaveKey('licence.ids_conflict')
        ->and($decisions)->not->toMatch('/`licence\.ids_conflict`[^\n]*pending EPOS/i');
});

test('framework errors on the till endpoints are contract codes: an unknown call or a wrong method is 400 request.invalid', function () {
    $headers = ['X-SSPOS-Contract' => '1'];

    foreach ([
        ['POST', '/api/v1/licence/nothing-here'],
        ['POST', '/api/v1/devices/activate'],      // deprecated, never built
        ['GET', '/api/v1/licence/activate'],
        ['GET', '/api/v1/sync/nothing-here'],
        ['DELETE', '/api/v1/sync/push'],
    ] as [$method, $uri]) {
        $this->json($method, $uri, [], $headers)->assertStatus(400)->assertJsonPath('code', 'request.invalid');
    }

    // Outside the till endpoints the framework names stay (the public trial form's API).
    $this->getJson('/api/v1/public/nothing-here')->assertNotFound()->assertJsonPath('code', 'request.not_found');
});

test('the reply guard refuses a code the contract does not list, a wrong status for a code, and a reply off its schema', function () {
    $error = fn (string $code) => json_encode(['code' => $code, 'message' => 'm', 'traceId' => 't', 'retryAfterSeconds' => null, 'rejectedKey' => null]);

    expect(ContractReplyGuard::check('POST api/v1/licence/activate', 404, $error('request.not_found')))->toContain('code request.not_found is not in error-codes.json')
        ->and(ContractReplyGuard::check('POST api/v1/licence/activate', 404, $error('key.already_used')))->toContain('code key.already_used is HTTP 409 in error-codes.json')
        ->and(ContractReplyGuard::check('POST api/v1/licence/activate', 409, $error('licence.ids_conflict')))->toBe([])
        ->and(ContractReplyGuard::check('POST api/v1/sync/push', 200, '{"acknowledgedSeq": 1, "accepted": 1}'))->not->toBe([])
        ->and(ContractReplyGuard::check('GET api/v1/sync/pull', 200, '{"changes": [], "highestVersion": 0, "hasMore": false}'))->toBe([]);
});
