<?php

namespace App\Domain\Sync\Enums;

/**
 * How a till id was matched to ours (contract §17.3 step 3, §17.8): the first till company id of a customer is
 * adopted, later ones (a second branch's main till) are aliased; branch and register ids are always adopted.
 */
enum IdMapAction: string
{
    case Adopted = 'adopted';
    case Aliased = 'aliased';
}
