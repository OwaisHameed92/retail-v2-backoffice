<?php

namespace App\Domain\ShopSettings\Support;

use App\Domain\Shared\Country\Country;

/**
 * The Till settings page for a till line with settings of its own (Pak POS pack 2026-10-07; profile
 * `till.settings` in config/country.php). GB has none, so its catalogue is exactly as written. Where the profile has:
 *
 * - `hidden`: keys (a trailing `*` matches any ending) left out of the page; a portal save of one is refused and the
 *   tills' values stay.
 * - `added`: section => key => definition, shared settings only that line has (shop.currency_symbol…).
 * - `labels`: key => [label, help] in place of the catalogue's.
 * - `defaults`: the start values shown for a key that is on the page (money settings in rupees).
 * - `moneyMaxFactor`: money settings' highest values multiplied by it.
 *
 * @phpstan-import-type Section from SettingCatalogue
 */
final class CountrySettings
{
    /**
     * @param  array<string, Section>  $sections
     * @return array<string, Section>
     */
    public static function apply(array $sections, Country $country): array
    {
        $profile = $country->till('settings');

        if (! is_array($profile) || $profile === []) {
            return $sections;
        }

        /** @var list<string> $hidden */
        $hidden = (array) ($profile['hidden'] ?? []);
        /** @var array<string, array<string, array<string, mixed>>> $added */
        $added = (array) ($profile['added'] ?? []);
        /** @var array<string, array{0: string, 1: string}> $labels */
        $labels = (array) ($profile['labels'] ?? []);
        /** @var array<string, string> $defaults */
        $defaults = (array) ($profile['defaults'] ?? []);
        $factor = (int) ($profile['moneyMaxFactor'] ?? 1);

        foreach ($sections as $id => &$section) {
            /** @var array<string, array<string, mixed>> $settings */
            $settings = [...$section['settings'], ...($added[$id] ?? [])];
            $section['settings'] = [];

            foreach ($settings as $key => $definition) {
                if (self::matches($key, $hidden)) {
                    continue;
                }

                if (isset($labels[$key])) {
                    [$definition['label'], $definition['help']] = $labels[$key];
                }

                if (isset($defaults[$key])) {
                    $definition['default'] = $defaults[$key];
                }

                if (($definition['type'] ?? null) === 'money' && isset($definition['max']) && $factor > 1) {
                    $definition['max'] = $definition['max'] * $factor;
                }

                /** @var array{label: string, help: string, type: string, min?: int|float, max?: int|float, unit?: string, default?: string, everyShopOnly?: bool, readOnly?: bool, options?: list<string>} $definition */
                $section['settings'][$key] = $definition;
            }
        }
        unset($section);

        return $sections;
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matches(string $key, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $key || (str_ends_with($pattern, '*') && str_starts_with($key, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
