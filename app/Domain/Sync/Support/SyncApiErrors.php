<?php

namespace App\Domain\Sync\Support;

use App\Domain\Shared\Exceptions\ApiException;

/**
 * The sync API's errors (contract §4.1, §9, §17.12, licensing/samples/error-codes.json; SIMPLE-SETUP.md §5 says what
 * the till shows). Messages are en-GB for the shop owner and never contain a key.
 */
final class SyncApiErrors
{
    public static function invalidKey(): ApiException
    {
        return new ApiException('auth.invalid_key', 'The portal does not know this sync key. Check it was copied in full from your online dashboard account.', 401);
    }

    public static function keyRevoked(): ApiException
    {
        return new ApiException('auth.key_revoked', 'This sync key has been withdrawn. Make a new one on your online dashboard account, then press Connect again.', 401);
    }

    public static function wrongBranch(): ApiException
    {
        return new ApiException('auth.wrong_branch', 'This sync key belongs to another shop. Use this shop\'s sync key.', 403);
    }

    public static function invalid(string $message): ApiException
    {
        return new ApiException('request.invalid', 'The till sent a sync request the portal could not read. '.$message, 400);
    }

    public static function tooLarge(string $message): ApiException
    {
        return new ApiException('batch.too_large', $message.' The till will send it again in smaller parts.', 413);
    }

    public static function rowInvalid(string $rejectedKey, string $message): ApiException
    {
        return new ApiException('row.invalid', $message, 422, null, $rejectedKey);
    }

    public static function busy(int $retryAfterSeconds): ApiException
    {
        return new ApiException('server.busy', 'The portal is still storing this shop\'s last batch. The till will try again in a moment.', 503, max(1, $retryAfterSeconds));
    }

    public static function rateLimited(int $retryAfterSeconds): ApiException
    {
        return new ApiException('rate.limited', 'Too many sync requests from this shop. The till will try again shortly.', 429, max(1, $retryAfterSeconds));
    }

    public static function idempotencyMismatch(): ApiException
    {
        return new ApiException('request.idempotency_mismatch', 'This request reused an Idempotency-Key with a different body.', 422);
    }
}
