<?php

namespace App\Domain\Licensing\Support;

use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;

final class UniqueLicenceKey
{
    /** A new random key whose hash no licence has (a clash is astronomically unlikely, but checked). */
    public static function generate(): LicenceKey
    {
        do {
            $key = LicenceKey::generate();
        } while (Licence::withoutCompanyScope()->withTrashed()->whereIn('key_hash', $key->hashCandidates())->exists());

        return $key;
    }
}
