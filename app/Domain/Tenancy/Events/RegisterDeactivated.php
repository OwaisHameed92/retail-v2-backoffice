<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Register;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A till was taken out of use (DeactivateRegister). Dispatched inside the action's transaction:
 * module 1.3 suspends the till's licence with the reason "Till deactivated".
 */
final class RegisterDeactivated
{
    use Dispatchable;

    public function __construct(public readonly Register $register) {}
}
