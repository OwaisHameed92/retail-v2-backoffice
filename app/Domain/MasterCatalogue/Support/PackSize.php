<?php

namespace App\Domain\MasterCatalogue\Support;

/**
 * A product's size: value + unit (g, kg, ml, cl, l, pack), and the count of a multipack ("4 x 440ml"). Read from a
 * product name or a CSV cell; shown as one short label. Weights and volumes also give the product form's
 * `volume_ml` / `net_mass_kg` (single packs only).
 */
final readonly class PackSize
{
    public const UNITS = ['g', 'kg', 'ml', 'cl', 'l', 'pack', 'each'];

    private const UNIT_ALIASES = ['ltr' => 'l', 'litre' => 'l', 'litres' => 'l', 'liter' => 'l', 'gm' => 'g', 'grams' => 'g', 'pk' => 'pack', 'ea' => 'each'];

    public function __construct(public ?string $value, public ?string $unit, public ?int $pack = null) {}

    public static function none(): self
    {
        return new self(null, null, null);
    }

    /** The size in a product name ("Heinz Baked Beans 415g", "Carlsberg 4 x 440ml", "Weetabix 24 Pack"). */
    public static function fromName(?string $name): self
    {
        $name = (string) $name;

        if (preg_match('/(\d{1,3})\s*x\s*(\d+(?:\.\d+)?)\s*(ml|cl|ltr|litres?|l|kg|g)\b/i', $name, $m) === 1) {
            return new self(self::number($m[2]), self::unit($m[3]), (int) $m[1]);
        }

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(ml|cl|ltr|litres?|l|kg|g)\b/i', $name, $all, PREG_SET_ORDER) > 0) {
            $m = end($all);

            return new self(self::number($m[1]), self::unit($m[2]));
        }

        if (preg_match('/\b(\d{1,3})\s*(pack|pk)\b/i', $name, $m) === 1) {
            return new self((string) (int) $m[1], 'pack');
        }

        return self::none();
    }

    /** A CSV size cell ("440ml", "4x440ml", "440") with an optional unit cell and pack cell. */
    public static function fromCells(?string $size, ?string $unit, ?string $pack): self
    {
        $size = trim((string) $size);
        $parsed = $size === '' ? self::none() : self::fromName($size);

        if ($parsed->value === null && is_numeric($size)) {
            $parsed = new self(self::number($size), null);
        }

        $unit = trim((string) $unit) === '' ? $parsed->unit : self::unit((string) $unit);
        $packQty = is_numeric(trim((string) $pack)) && (int) $pack > 1 ? (int) $pack : $parsed->pack;

        return new self($parsed->value, in_array($unit, self::UNITS, true) ? $unit : null, $packQty);
    }

    public function label(): ?string
    {
        if ($this->value === null) {
            return null;
        }

        $value = self::trim($this->value);
        $base = match ($this->unit) {
            'pack' => "{$value} pack",
            'each' => "{$value} each",
            null => $value,
            default => $value.$this->unit,
        };

        return $this->pack !== null && $this->pack > 1 ? "{$this->pack} x {$base}" : $base;
    }

    /** Millilitres of a single (not multi-) pack, for the product form; null otherwise. */
    public function volumeMl(): ?string
    {
        if ($this->value === null || ($this->pack ?? 1) > 1) {
            return null;
        }

        $factor = ['ml' => 1, 'cl' => 10, 'l' => 1000][$this->unit ?? ''] ?? null;

        return $factor === null ? null : self::trim(number_format((float) $this->value * $factor, 4, '.', ''));
    }

    /** Kilograms of a single pack, for the product form; null otherwise. */
    public function massKg(): ?string
    {
        if ($this->value === null || ($this->pack ?? 1) > 1) {
            return null;
        }

        $factor = ['g' => 0.001, 'kg' => 1][$this->unit ?? ''] ?? null;

        return $factor === null ? null : self::trim(number_format((float) $this->value * $factor, 4, '.', ''));
    }

    public static function trim(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    private static function number(string $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    private static function unit(string $unit): string
    {
        $unit = strtolower(trim($unit));

        return self::UNIT_ALIASES[$unit] ?? $unit;
    }
}
