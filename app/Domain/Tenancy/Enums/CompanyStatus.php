<?php

namespace App\Domain\Tenancy\Enums;

/**
 * Commercial status of a tenant company. Values are camelCase strings (contract convention).
 *
 * Transitions (Actions in App\Domain\Tenancy\Actions):
 * - ActivateCompany:  trial | overdue | cancelled → active
 * - SuspendCompany:   trial | active | overdue    → suspended (reason required; previous status remembered)
 * - UnsuspendCompany: suspended                   → the status it had before suspension
 * - CancelCompany:    any but cancelled           → cancelled (reason required)
 */
enum CompanyStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Overdue = 'overdue';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::Overdue => 'Overdue',
            self::Suspended => 'Suspended',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Customer users may use the tenant portal.
     */
    public function allowsPortalAccess(): bool
    {
        return ! in_array($this, [self::Suspended, self::Cancelled], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
