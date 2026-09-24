<?php

namespace App\Domain\TillData\Generator;

/**
 * One stored payload field of a till entity, as the generator sees it.
 *
 * Types: string, text, longText, int, bigint, money, cost, quantity, percent, rate, bool, date, time, datetime,
 * enum, json, secret. `arg` is the varchar length (string, enum), the enum class short name (enum) or null.
 */
final readonly class FieldSpec
{
    /**
     * @param  list<string>  $enumValues
     */
    public function __construct(
        public string $name,
        public string $column,
        public string $type,
        public bool $nullable,
        public int|string|null $arg = null,
        public array $enumValues = [],
    ) {}

    public function isDecimal(): bool
    {
        return in_array($this->type, ['money', 'cost', 'quantity', 'percent', 'rate'], true);
    }

    /**
     * The PHP type a model attribute has after casting (for @property docs).
     */
    public function phpType(): string
    {
        $type = match ($this->type) {
            'int', 'bigint' => 'int',
            'bool' => 'bool',
            'date', 'datetime' => '\Carbon\CarbonImmutable',
            'enum' => (string) $this->arg,
            'json' => 'array<mixed>',
            default => 'string',
        };

        // Unknown enum values are stored as sent but read back as null (TillEnumCast), so enums are always nullable.
        return $this->nullable || $this->type === 'enum' ? $type.'|null' : $type;
    }
}
