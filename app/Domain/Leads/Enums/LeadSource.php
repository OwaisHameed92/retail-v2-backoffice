<?php

namespace App\Domain\Leads\Enums;

/**
 * How the lead reached us. camelCase values (contract convention).
 */
enum LeadSource: string
{
    case Website = 'website';
    case Phone = 'phone';
    case Referral = 'referral';
    case WalkIn = 'walkIn';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::Phone => 'Phone call',
            self::Referral => 'Referral',
            self::WalkIn => 'Walk-in',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $source) => ['value' => $source->value, 'label' => $source->label()], self::cases());
    }
}
