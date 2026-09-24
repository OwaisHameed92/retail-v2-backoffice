<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Register;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A till was added to a branch (AddRegister). Dispatched inside the action's transaction and company scope:
 * listeners run in the same transaction (module 1.3 issues the till's licence here).
 */
final class RegisterAdded
{
    use Dispatchable;

    public function __construct(public readonly Register $register) {}
}
