<?php

namespace App\Domain\TillData\Concerns;

use App\Domain\TillData\Exceptions\ReadOnlyTillRow;
use Illuminate\Database\Eloquent\Model;

/**
 * Branch-owned till row: read-only on the portal. Saving, deleting or restoring through Eloquent throws.
 * The sync applier writes with the query builder, which is the only allowed writer.
 *
 * @mixin Model
 */
trait TillOwnedRow
{
    public static function bootTillOwnedRow(): void
    {
        $refuse = fn (Model $model) => throw ReadOnlyTillRow::for($model);

        static::saving($refuse);
        static::deleting($refuse);
        static::restoring($refuse);
    }

    public function isHubOwned(): bool
    {
        return false;
    }
}
