<?php

namespace App\Domain\Licensing\Support;

use App\Domain\Licensing\Models\Licence;
use Illuminate\Validation\ValidationException;

/**
 * Shared steps of the licence actions: reload the row under a lock (inside the action's transaction) and refuse
 * changes to revoked licences. Validation errors use the `status` key so admin screens show them as toasts.
 */
final class LicenceGuard
{
    /** A fresh copy of the licence, locked for update. Call inside a transaction. */
    public static function lock(Licence $licence): Licence
    {
        return Licence::withoutCompanyScope()->lockForUpdate()->findOrFail($licence->getKey());
    }

    /**
     * @throws ValidationException
     */
    public static function ensureNotRevoked(Licence $licence, string $what): void
    {
        if ($licence->isRevoked()) {
            throw ValidationException::withMessages(['status' => "This licence is revoked, so you cannot {$what}. Issue a new licence for the till instead."]);
        }
    }

    /**
     * Trim a reason and require one.
     *
     * @throws ValidationException
     */
    public static function reason(string $reason, string $message): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => $message]);
        }

        return mb_substr($reason, 0, 500);
    }
}
