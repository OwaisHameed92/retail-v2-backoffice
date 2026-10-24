<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * What a store's aisles are built from (OS-11, StoreAisles): the till's departments or its categories. Automatic (no
 * value stored) picks departments when there are at least three real ones, else whichever gives more aisles.
 */
enum AisleSource: string
{
    case Departments = 'departments';
    case Categories = 'categories';

    public function label(): string
    {
        return match ($this) {
            self::Departments => 'Departments',
            self::Categories => 'Categories',
        };
    }
}
