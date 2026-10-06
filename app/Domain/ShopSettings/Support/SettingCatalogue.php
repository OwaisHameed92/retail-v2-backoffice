<?php

namespace App\Domain\ShopSettings\Support;

use App\Domain\Shared\Country\Country;
use App\Domain\TillData\Sync\SettingSyncPolicy;
use Illuminate\Validation\ValidationException;

/**
 * The till settings the portal edits (module 4.9), from `app/Domain/ShopSettings/catalogue.php`: sections of
 * settings with a label, help text and a type. `normalise()` turns what a person typed into the text the till
 * stores (`true`/`false`, `20`, `2.50`), or refuses it. Deny-listed keys are never part of it (a test checks); a
 * `readOnly` key is shown but always refused (the tills set it).
 *
 * @phpstan-type Definition array{label: string, help: string, type: string, min?: int|float, max?: int|float, unit?: string, default?: string, everyShopOnly?: bool, readOnly?: bool, options?: list<string>}
 * @phpstan-type Section array{title: string, description: string, settings: array<string, Definition>}
 */
final class SettingCatalogue
{
    public const TYPES = ['bool', 'int', 'money', 'percent', 'decimal', 'text', 'multiline', 'choice', 'time'];

    /** @var array<string, Section>|null */
    private static ?array $sections = null;

    /**
     * @return array<string, Section>
     */
    public static function sections(): array
    {
        /** @var array<string, Section> $sections */
        $sections = self::$sections ??= require dirname(__DIR__).'/catalogue.php';
        $country = app(Country::class);

        return $country->is(Country::DEFAULT) ? $sections : self::localised($sections, $country);
    }

    /**
     * The catalogue's words for another country (Pakistan plan P3): the tax name ("GST") and the business ids (NTN,
     * SECP) instead of the UK's. GB reads the catalogue exactly as written.
     *
     * @param  array<string, Section>  $sections
     * @return array<string, Section>
     */
    private static function localised(array $sections, Country $country): array
    {
        $vat = $country->taxIdFor('vat_number');
        $company = $country->taxIdFor('company_number');
        // [label, help] of the business-id settings.
        $ids = array_filter([
            'shop.vat_number' => $vat === null ? null : [
                $vat['label'].' on receipts',
                "Printed on receipts and {$country->taxName()} invoices, for example {$vat['example']}.",
            ],
            'shop.company_number' => $company === null ? null : [$company['label'], "Your {$company['label']}, if you are a company."],
        ]);

        foreach ($sections as &$section) {
            $section['title'] = $country->taxText($section['title']);
            $section['description'] = $country->taxText($section['description']);

            foreach ($section['settings'] as $key => &$definition) {
                [$definition['label'], $definition['help']] = $ids[$key] ?? [$country->taxText($definition['label']), $country->taxText($definition['help'])];
            }
            unset($definition);
        }
        unset($section);

        return $sections;
    }

    /**
     * @return array<string, Definition>
     */
    public static function all(): array
    {
        return array_merge(...array_values(array_map(fn (array $section) => $section['settings'], self::sections())));
    }

    /**
     * @return Definition|null
     */
    public static function find(string $key): ?array
    {
        $definition = self::all()[$key] ?? null;

        return $definition === null || SettingSyncPolicy::isLocalOnly('company', $key) ? null : $definition;
    }

    /**
     * The value as the till stores it; `null` (or blank) removes the setting here so the wider one applies.
     *
     * @throws ValidationException
     */
    public static function normalise(string $key, mixed $value): ?string
    {
        $definition = self::find($key) ?? throw ValidationException::withMessages(["values.{$key}" => 'This setting cannot be changed from the portal.']);
        $field = "values.{$key}";

        if ($definition['readOnly'] ?? false) {
            throw ValidationException::withMessages([$field => "{$definition['label']} is set at a till; the portal only shows it."]);
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        if (! is_scalar($value)) {
            throw ValidationException::withMessages([$field => "{$definition['label']}: enter a value."]);
        }

        $text = trim((string) $value);

        return match ($definition['type']) {
            'bool' => in_array(strtolower($text), ['true', 'false'], true) ? strtolower($text)
                : throw ValidationException::withMessages([$field => "{$definition['label']}: choose on or off."]),
            'text', 'multiline' => self::text($definition, $field, (string) $value),
            // The till's own texts, exactly ("WhatsApp, then email", "Custom"); a time is HH:mm shop time.
            'choice' => in_array($text, $definition['options'] ?? [], true) ? $text
                : throw ValidationException::withMessages([$field => "{$definition['label']}: choose one of the options."]),
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $text) === 1 ? $text
                : throw ValidationException::withMessages([$field => "{$definition['label']}: enter a time like 21:00."]),
            default => self::number($definition, $field, $text),
        };
    }

    /**
     * @param  Definition  $definition
     *
     * @throws ValidationException
     */
    private static function text(array $definition, string $field, string $value): string
    {
        $value = $definition['type'] === 'text' ? trim($value) : rtrim(str_replace("\r\n", "\n", $value));
        $max = (int) ($definition['max'] ?? 200);

        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages([$field => "{$definition['label']}: at most {$max} characters."]);
        }

        return $value;
    }

    /**
     * @param  Definition  $definition
     *
     * @throws ValidationException
     */
    private static function number(array $definition, string $field, string $text): string
    {
        $country = app(Country::class);
        // The symbol people may type ("£2.50", "Rs 250").
        $text = $country->symbolSpace() ? ltrim(ltrim($text, $country->symbol())) : ltrim($text, $country->symbol());
        [$pattern, $places, $what] = match ($definition['type']) {
            'int' => ['/^\d+$/', 0, 'a whole number'],
            'money' => ['/^\d+(\.\d{1,2})?$/', 2, 'an amount in '.$country->currencyName().', like 2.50'],
            'percent' => ['/^\d+(\.\d{1,2})?$/', null, 'a percentage, like 10'],
            default => ['/^\d+(\.\d{1,4})?$/', null, 'a number'],
        };

        if (preg_match($pattern, $text) !== 1) {
            throw ValidationException::withMessages([$field => "{$definition['label']}: enter {$what}."]);
        }

        $min = (string) ($definition['min'] ?? 0);
        $max = isset($definition['max']) ? (string) $definition['max'] : null;

        if (bccomp($text, $min, 4) < 0 || ($max !== null && bccomp($text, $max, 4) > 0)) {
            $range = $max === null ? "at least {$min}" : "between {$min} and {$max}";
            throw ValidationException::withMessages([$field => "{$definition['label']}: enter a value {$range}."]);
        }

        return match ($places) {
            0 => bcadd($text, '0', 0),
            2 => bcadd($text, '0', 2),
            default => rtrim(rtrim(bcadd($text, '0', 4), '0'), '.'),
        };
    }
}
