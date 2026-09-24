<?php

namespace App\Domain\TillData\Registry;

use BackedEnum;

/**
 * One stored payload field: till name, portal column, type (see EntityRegistry), nullability and
 * the varchar length or enum class.
 */
final readonly class FieldDefinition
{
    /** Varchar length of enum columns (FieldTyper::ENUM_LENGTH). */
    public const ENUM_LENGTH = 40;

    public function __construct(
        public string $name,
        public string $column,
        public string $type,
        public bool $nullable,
        public int|string|null $arg,
    ) {}

    /**
     * Columns this field writes (a secret writes a hash and the last 4 characters).
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->type === 'secret' ? [$this->column.'_hash', $this->column.'_last4'] : [$this->column];
    }

    /**
     * @return class-string<BackedEnum>|null
     */
    public function enumClass(): ?string
    {
        /** @var class-string<BackedEnum>|null */
        return $this->type === 'enum' ? (string) $this->arg : null;
    }

    public function maxLength(): ?int
    {
        return match ($this->type) {
            'string' => (int) $this->arg,
            'enum' => self::ENUM_LENGTH,
            'text' => 65535,
            default => null,
        };
    }
}
