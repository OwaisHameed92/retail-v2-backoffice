<?php

namespace App\Domain\Sync\Enums;

/** What a till id in `id_map` names (module 2.1). */
enum IdKind: string
{
    case Company = 'company';
    case Branch = 'branch';
    case Register = 'register';
}
