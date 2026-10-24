<?php

namespace App\Domain\TillImages\Enums;

/** Where a product picture came from (IMG-1). Only `till` is written today; `portal` is the later portal→till phase. */
enum ProductImageSource: string
{
    case Till = 'till';
    case Portal = 'portal';
}
