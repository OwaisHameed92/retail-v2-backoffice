<?php

namespace App\Domain\Licensing\Events;

use App\Domain\Licensing\Models\Licence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A till key was activated for the first time (LicenceBinder::bind: `licence/activate`, a portal key redeemed at a
 * linked till, `cloud/migrate`), inside the activation's transaction. Licensing does nothing with it; the partner API
 * sends `tenant.first_till_activated` for the business's first one.
 */
final class LicenceActivated
{
    use Dispatchable;

    public function __construct(public readonly Licence $licence) {}
}
