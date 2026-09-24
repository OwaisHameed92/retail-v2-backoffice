<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Shared\Support\Ulid;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * ULID primary key made by the portal and sent to the till. Upper case, like till-made ids
 * (Laravel's HasUlids would store lower case, which the till contract and ValidUlid reject).
 */
trait HasPortalUlid
{
    use HasUlids;

    public function newUniqueId(): string
    {
        return Ulid::new();
    }
}
