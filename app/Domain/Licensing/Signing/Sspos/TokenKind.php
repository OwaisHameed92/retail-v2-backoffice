<?php

namespace App\Domain\Licensing\Signing\Sspos;

/**
 * Licence token `kind` (contract §17.2). `trial` with no features = every feature on; `full` = exactly the
 * listed features.
 */
enum TokenKind: string
{
    case Trial = 'trial';
    case Full = 'full';
}
