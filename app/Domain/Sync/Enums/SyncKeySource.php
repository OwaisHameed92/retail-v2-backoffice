<?php

namespace App\Domain\Sync\Enums;

/** Who made a sync key: the licence API for the branch's main till, or an admin ("Connect" path). */
enum SyncKeySource: string
{
    case Till = 'till';
    case Admin = 'admin';
}
