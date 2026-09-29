<?php

namespace App\Http\Controllers\Api;

use App\Domain\Sync\Actions\CompleteCloudMigration;
use App\Domain\Sync\Actions\MigrateToCloud;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSyncKey;
use App\Http\Middleware\EnsureTillContract;
use App\Http\Requests\Api\CompleteMigrationRequest;
use App\Http\Requests\Api\MigrateRequest;
use Illuminate\Http\JsonResponse;

/**
 * A shop moving to the cloud (module 2.8, contract v1.4.1 §17.8): `POST cloud/migrate` (the sync key in the body) and
 * `POST cloud/migrate/complete` (Bearer the key from the migrate reply). The history itself goes up through
 * `sync/push` in initial mode. Thin: validate, call one Action, reply JSON.
 */
class CloudMigrationController extends Controller
{
    public function migrate(MigrateRequest $request, MigrateToCloud $migrate): JsonResponse
    {
        return self::json($migrate->handle($request->migrationInput()));
    }

    public function complete(CompleteMigrationRequest $request, CompleteCloudMigration $complete): JsonResponse
    {
        return self::json($complete->handle(
            AuthenticateSyncKey::caller($request),
            $request->header(EnsureTillContract::INSTALL_ID_HEADER),
            (string) $request->validated('uploadId'),
            (int) $request->validated('totalRows'),
            (int) $request->validated('highestSeq'),
            $request->rowCounts(),
            (int) $request->validated('snapshotChangeLogSeq'),
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data): JsonResponse
    {
        return new JsonResponse($data, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
