<?php

namespace App\Domain\Sync\Support;

use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Sync\Models\CloudUpload;

/**
 * `cloud/migrate`, `cloud/migrate/complete` and initial-upload refusals (contract v1.4.1 §17.8, §17.12,
 * error-codes.json, SIMPLE-SETUP.md §5: the till shows its own sentence for the sync key codes). en-GB, for the shop
 * owner; never a key.
 */
final class MigrationErrors
{
    public static function codeNotFound(): ApiException
    {
        return new ApiException('activation.code_not_found', 'That sync key is not recognised. Check it was copied in full from your online dashboard account.', 404);
    }

    public static function codeExpired(): ApiException
    {
        return new ApiException('activation.code_expired', 'This sync key has expired. Make a new one on your online dashboard account, then press Connect again.', 410);
    }

    public static function codeUsed(?string $deviceName, mixed $usedAt): ApiException
    {
        return new ApiException('activation.code_used', 'This licence key is already in use on '.($deviceName ?? 'another PC').'. Ask your dealer to release it first.', 409, null, null, [
            'usedAtUtc' => ApiDate::format($usedAt),
            'deviceName' => $deviceName,
        ]);
    }

    public static function branchAlreadyLinked(string $branchName, CloudUpload $upload, ?string $tillRegisterId): ApiException
    {
        $device = $upload->device_name ?? 'another PC';

        return new ApiException('device.branch_already_linked', "{$branchName} is already linked to {$device}. Release that till first, on the portal or on the old till.", 409, null, null, [
            'registerId' => $tillRegisterId,
            'deviceName' => $upload->device_name,
            'lastSeenUtc' => ApiDate::format($upload->last_batch_at ?? $upload->updated_at),
        ]);
    }

    public static function alreadyMigrated(string $message): ApiException
    {
        return new ApiException('migrate.already_migrated', $message.' '.LicenceApiErrors::SUPPORT, 409);
    }

    public static function uploadNotFound(): ApiException
    {
        return new ApiException('migrate.upload_not_found', 'The portal has no open upload with this id. The till starts the move to the cloud again.', 404);
    }

    public static function uploadClosed(): ApiException
    {
        return new ApiException('migrate.upload_closed', 'This upload is already complete. The till carries on with normal sync.', 409);
    }

    public static function notMainTill(): ApiException
    {
        return new ApiException('device.not_main_till', 'Only the till that started the move to the cloud can finish it.', 403);
    }
}
