<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Register;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A till was put back into use (ReactivateRegister). Dispatched inside the action's transaction:
 * module 1.3 lifts a "Till deactivated" licence suspension.
 */
final class RegisterReactivated
{
    use Dispatchable;

    public function __construct(public readonly Register $register) {}
}
