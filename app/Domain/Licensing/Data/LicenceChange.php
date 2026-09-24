<?php

namespace App\Domain\Licensing\Data;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;

/**
 * Result of an action that changes one licence (suspend, unsuspend, revoke, reset device, change plan).
 * `changed` is false when there was nothing to do (e.g. the till was already unsuspended).
 */
final readonly class LicenceChange
{
    public function __construct(
        public Licence $licence,
        public LicenceStatus $from,
        public LicenceStatus $to,
        public bool $changed = true,
    ) {}
}
