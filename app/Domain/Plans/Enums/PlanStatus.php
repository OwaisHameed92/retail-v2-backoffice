<?php

namespace App\Domain\Plans\Enums;

/**
 * Display status of a plan, derived from `is_active`, `is_public` and `deleted_at`:
 *
 * | Status   | Meaning                                                              |
 * |----------|----------------------------------------------------------------------|
 * | active   | Can be given to new licences and is shown on the pricing page.       |
 * | hidden   | Can be given to new licences but is not on the pricing page.         |
 * | inactive | Kept for existing licences; cannot be chosen for new ones.           |
 * | archived | Soft deleted. Read-only until restored.                              |
 */
enum PlanStatus: string
{
    case Active = 'active';
    case Hidden = 'hidden';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Hidden => 'Hidden',
            self::Inactive => 'Inactive',
            self::Archived => 'Archived',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
