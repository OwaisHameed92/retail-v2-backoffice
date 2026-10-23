<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Str;

/**
 * The address a store gets when an admin switches the add-on on (OS-6): the business name as a slug ("Khan Mini Mart"
 * → `khan-mini-mart`), with `-2`, `-3`… when taken or reserved, else `store-` and random letters. The business can
 * change it in the store builder (StoreSlug rules).
 */
final class AddOnStoreSlug
{
    public static function suggest(Company $company): string
    {
        $base = trim(substr(Str::slug($company->name), 0, 34), '-');

        if (strlen($base) >= 3) {
            for ($n = 1; $n <= 50; $n++) {
                $slug = $n === 1 ? $base : $base.'-'.$n;

                if (self::free($slug)) {
                    return $slug;
                }
            }
        }

        do {
            $slug = 'store-'.Str::lower(Str::random(8));
        } while (! self::free($slug));

        return $slug;
    }

    private static function free(string $slug): bool
    {
        return StoreSlug::problem($slug) === null && ! OnlineStore::withoutCompanyScope()->where('slug', $slug)->exists();
    }
}
