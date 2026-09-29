<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\ContractReplyGuard;
use Tests\Support\ContractSampleCoverage;
use Tests\Support\ContractSchema;

/*
 * Module 2.6 (contract v1.4.1 §14, §19.4, §21): every sample file of the package is a valid message of its schema,
 * and every one is replayed by a named test (tests/Support/ContractSampleCoverage.php) or is explicitly pending.
 */

/** @return list<string> every sample file, relative to docs/web-portal-api */
function contractSampleFiles(): array
{
    $files = [];
    $root = ContractSchema::dir();

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr((string) $file, strlen($root) + 1);

        if (str_ends_with($relative, '.json') && preg_match('#^(samples|licensing/samples)/#', $relative) === 1) {
            $files[] = $relative;
        }
    }

    sort($files);

    return $files;
}

test('every sample file is a valid message of its schema', function (string $sample) {
    $data = json_decode((string) file_get_contents(ContractSchema::dir($sample)), false, 512, JSON_THROW_ON_ERROR);
    $schema = ContractSampleCoverage::schemaFor($sample);

    $errors = match ($schema) {
        null => [],
        // The package's own feeds: envelopes and payloads with every member (derived ones too).
        'pull' => [
            ...ContractSchema::errors($data, 'schemas/pull-reply.schema.json'),
            ...array_merge([], ...array_map(fn ($c) => ContractSchema::changeErrors($c), (array) ($data->changes ?? []))),
        ],
        'push' => [
            ...ContractSchema::errors($data, 'schemas/push-request.schema.json'),
            ...array_merge([], ...array_map(fn ($c) => ContractSchema::changeErrors($c), (array) $data)),
        ],
        default => ContractSchema::errors($data, $schema),
    };

    expect($errors)->toBe([]);
})->with(contractSampleFiles());

test('the validator refuses what the schemas refuse (it is not a pass-through)', function () {
    $push = json_decode((string) file_get_contents(ContractSchema::dir('samples/push-request.json')));
    $push[0]->payload->total = 'five pounds';
    $push[0]->op = 'X';
    $reply = json_decode('{"acknowledgedSeq": 1.5, "accepted": 1, "receivedAt": "2026-09-23 09:42:13"}');

    expect(ContractSchema::changeErrors($push[0]))->toHaveCount(2)
        ->and(ContractSchema::errors($reply, 'schemas/push-reply.schema.json'))->not->toBe([])
        ->and(ContractSchema::errors(['code' => 'x'], 'licensing/schemas/error-reply.schema.json'))->not->toBe([])
        // A cross-file $ref (licensing → common.schema.json) is followed.
        ->and(ContractSchema::errors(['licenceId' => 'not-a-ulid'], 'licensing/schemas/validate-request.schema.json'))->not->toBe([]);
});

test('every sample file is replayed by a named test, or is explicitly pending or not applicable', function () {
    $map = ContractSampleCoverage::map();
    $files = contractSampleFiles();

    expect(array_values(array_diff($files, array_keys($map))))->toBe([], 'sample files without a test: add them to ContractSampleCoverage')
        ->and(array_values(array_diff(array_keys($map), $files)))->toBe([], 'ContractSampleCoverage lists files that no longer exist');

    foreach ($map as $sample => $entry) {
        expect(isset($entry['tests']) || isset($entry['pending']) || isset($entry['notApplicable']))->toBeTrue($sample);

        foreach ($entry['tests'] ?? [] as [$file, $name]) {
            $path = base_path("tests/Feature/{$file}");
            expect(is_file($path))->toBeTrue("{$sample}: {$file} does not exist");

            $source = (string) file_get_contents($path);
            expect(str_contains($source, "it('{$name}") || str_contains($source, "test('{$name}"))
                ->toBeTrue("{$sample}: no test named \"{$name}…\" in {$file}");
        }
    }
});

test('a pending sample\'s endpoint is not routed yet: once it is, its samples must be replayed', function () {
    $pending = array_filter(ContractSampleCoverage::map(), fn (array $entry) => isset($entry['route']));

    // Module 2.8 routed the last pending endpoints (licence/redeem, cloud/migrate, migrate/complete): every till route
    // now has its reply schema in the guard, and no licensing sample is pending any more.
    $tillRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => preg_match('#^api/v1/(sync|licence|devices|cloud)/#', $route->uri()) === 1)
        ->map(fn ($route) => ($route->methods()[0]).' '.$route->uri())->values()->all();

    expect($tillRoutes)->not->toBeEmpty()
        ->and(array_diff($tillRoutes, array_keys(ContractReplyGuard::REPLY_SCHEMAS)))->toBe([])
        ->and(array_filter(array_keys($pending), fn (string $sample) => str_starts_with($sample, 'licensing/')))->toBe([]);

    foreach ($pending as $sample => ['route' => $route]) {
        [$method, $uri] = explode(' ', $route);

        try {
            Route::getRoutes()->match(Request::create('/'.$uri, $method));
            $routed = true;
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            $routed = false;
        }

        expect($routed)->toBeFalse("{$route} now exists: replay {$sample} and move it to `tests` in ContractSampleCoverage");
    }
});
