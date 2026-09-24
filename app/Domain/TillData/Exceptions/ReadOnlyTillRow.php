<?php

namespace App\Domain\TillData\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Thrown when portal code tries to save or delete a branch-owned till row. The till's version always wins
 * (contract section 10); only the sync applier writes these tables.
 */
final class ReadOnlyTillRow extends LogicException
{
    public static function for(Model $model): self
    {
        return new self('Till rows of ['.$model::class.'] are read-only on the portal; only sync writes them.');
    }
}
