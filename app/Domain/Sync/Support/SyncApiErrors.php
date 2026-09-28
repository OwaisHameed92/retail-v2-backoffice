<?php

namespace App\Domain\Sync\Support;

use App\Domain\Shared\Exceptions\ApiException;

/**
 * The sync API's auth errors (contract §4.1, §9, licensing/samples/error-codes.json; SIMPLE-SETUP.md §5 says what
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
}
