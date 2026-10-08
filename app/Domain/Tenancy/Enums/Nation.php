<?php

namespace App\Domain\Tenancy\Enums;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\TillProfile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * The nation a branch trades in (drives DRS, licensing and VAT rules on the UK till). camelCase contract values for the
 * UK; `Pakistan` exactly as the Pak POS till writes it (pack 2026-10-07: Branch `nation` is a free string there and
 * every Pakistan shop carries "Pakistan"). Pakistan is used only where the profile names it (TillProfile::branchNation).
 */
enum Nation: string
{
    case England = 'england';
    case Scotland = 'scotland';
    case Wales = 'wales';
    case NorthernIreland = 'northernIreland';
    case Pakistan = 'Pakistan';

    public function label(): string
    {
        return match ($this) {
            self::England => 'England',
            self::Scotland => 'Scotland',
            self::Wales => 'Wales',
            self::NorthernIreland => 'Northern Ireland',
            self::Pakistan => 'Pakistan',
        };
    }

    /**
     * The nations the country profile offers on the shop forms (phase P9): all four on GB, none on PK, where the field
     * is hidden and every shop gets the profile's nation (`forShop`).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $offered = app(Country::class)->nations();
        $cases = array_values(array_filter(self::cases(), fn (self $nation) => in_array($nation->value, $offered, true)));

        return array_map(fn (self $nation) => ['value' => $nation->value, 'label' => $nation->label()], $cases);
    }

    /** True when the country profile shows the nation on shop forms and pages (GB). */
    public static function shown(): bool
    {
        return app(Country::class)->nations() !== [];
    }

    /** The label where the profile shows nations ("England"), null elsewhere. */
    public function shownLabel(): ?string
    {
        return self::shown() ? $this->label() : null;
    }

    /**
     * What a shop form may send: GB exactly the four UK nations as before; PK any (the hidden field sends the column
     * default), as the shop gets the profile's nation anyway (`forShop`).
     */
    public static function rule(): Enum
    {
        $rule = Rule::enum(self::class);

        return TillProfile::branchNation() === null ? $rule->except([self::Pakistan]) : $rule;
    }

    /** The nation a shop made or edited on the portal gets: the profile's (PK "Pakistan"), else the one picked (GB). */
    public static function forShop(string $sent): self
    {
        return self::from(TillProfile::branchNation() ?? $sent);
    }

    /**
     * A nation a till pushed that the portal stores, null = keep ours. GB: one of the four UK nations, as before. PK:
     * only the profile's ("Pakistan"); a till's older default ("England") does not overwrite it.
     */
    public static function fromTill(mixed $value): ?self
    {
        $fixed = TillProfile::branchNation();
        $nation = is_string($value) ? self::tryFrom($value) : null;

        if ($fixed !== null) {
            return $nation !== null && $nation->value === $fixed ? $nation : null;
        }

        return $nation === self::Pakistan ? null : $nation;
    }
}
