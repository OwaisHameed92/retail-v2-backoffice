<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Enums\StoreTemplate;

/**
 * The public store's colours from its one brand colour (OS-8): every button, link, badge, active tab, chip, focus ring,
 * price, header strip, footer, phone bar and toast reads these, so a store's own colour shows everywhere. Text on each
 * background is chosen for WCAG AA (4.5:1 for text, 3:1 for focus rings and large shapes):
 *
 * - `brand` / `onBrand`: the colour itself and white or ink on it, whichever reads better (at least 4.5:1 where the
 *   colour allows; a mid colour gets the better of the two).
 * - `brandHover`: a little darker (lighter for a very dark colour), for hovers.
 * - `brandText`: the colour darkened until it reads on white and on the template's paper (4.5:1): links, prices, icons.
 * - `brandDeep`: a deep shade for the footer and toasts, white text at 7:1 or more.
 * - `brandSoft` / `brandTint`: light tints for chips, selected rows and placeholders (ink text).
 * - `highlight` / `onHighlight` / `highlightSoft`: the template's highlight. Fresh Market keeps its lime with a green
 *   or neutral brand, else a light shade of the brand's own hue; Butcher's Block keeps its tan.
 *
 *     BrandPalette::for('#B42318', StoreTemplate::FreshMarket)['onBrand']; // '#FFFFFF'
 */
final class BrandPalette
{
    public const WHITE = '#FFFFFF';

    public const LIME = '#CFF27A';

    public const LIME_SOFT = '#EAF8C4';

    private const INK = [StoreTemplate::FreshMarket->value => '#0E1712', StoreTemplate::Butcher->value => '#1C1917'];

    private const PAPER = [StoreTemplate::FreshMarket->value => '#F4F3EE', StoreTemplate::Butcher->value => '#F7F3EC'];

    /**
     * @return array{brand: string, onBrand: string, brandHover: string, brandText: string, brandDeep: string, brandSoft: string, brandTint: string, highlight: string, onHighlight: string, highlightSoft: string}
     */
    public static function for(string $brand, StoreTemplate $template): array
    {
        $brand = self::normalise($brand) ?? self::normalise($template->defaultBrand()) ?? '#0B4A34';
        $ink = self::INK[$template->value];
        $paper = self::PAPER[$template->value];
        [$highlight, $highlightSoft] = self::highlight($brand, $template);

        return [
            'brand' => $brand,
            'onBrand' => self::readableOn($brand, $ink),
            'brandHover' => self::luminance($brand) < 0.02 ? self::mix($brand, self::WHITE, 0.16) : self::mix($brand, '#000000', 0.14),
            'brandText' => self::darkenUntil($brand, [self::WHITE, $paper], 4.5),
            'brandDeep' => self::darkenUntil(self::mix($brand, $ink, 0.45), [self::WHITE], 7.0),
            'brandSoft' => self::mix($brand, self::WHITE, 0.92),
            'brandTint' => self::mix($brand, self::WHITE, 0.82),
            'highlight' => $highlight,
            'onHighlight' => self::readableOn($highlight, $ink),
            'highlightSoft' => $highlightSoft,
        ];
    }

    /** "#0B4A34" for "#0b4a34" or "0B4A34"; null when it is not a 6-digit hex colour. */
    public static function normalise(?string $hex): ?string
    {
        $hex = ltrim(trim((string) $hex), '#');

        return preg_match('/^[0-9A-Fa-f]{6}$/', $hex) === 1 ? '#'.strtoupper($hex) : null;
    }

    /** White or the ink, whichever has the higher contrast on the background. */
    public static function readableOn(string $background, string $ink = '#0E1712'): string
    {
        return self::contrast($background, self::WHITE) >= self::contrast($background, $ink) ? self::WHITE : strtoupper($ink);
    }

    /** WCAG contrast ratio of two colours, 1 to 21. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** WCAG relative luminance, 0 (black) to 1 (white). */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $channel = fn (int $c): float => ($v = $c / 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /** `$a` moved towards `$b` by `$amount` (0 = $a, 1 = $b), as "#RRGGBB". */
    public static function mix(string $a, string $b, float $amount): string
    {
        $amount = max(0.0, min(1.0, $amount));
        $x = self::rgb($a);
        $y = self::rgb($b);

        return self::hex(array_map(fn (int $i) => (int) round($x[$i] + ($y[$i] - $x[$i]) * $amount), [0, 1, 2]));
    }

    /**
     * The colour, darkened in small steps until it has at least `$ratio` contrast with every background.
     *
     * @param  list<string>  $backgrounds
     */
    public static function darkenUntil(string $hex, array $backgrounds, float $ratio): string
    {
        $colour = self::normalise($hex) ?? '#000000';

        for ($step = 0; $step <= 20; $step++) {
            $candidate = self::mix($colour, '#000000', $step * 0.05);

            if (min(array_map(fn (string $bg) => self::contrast($candidate, $bg), $backgrounds)) >= $ratio) {
                return $candidate;
            }
        }

        return '#000000';
    }

    /**
     * @return array{0: string, 1: string} the highlight and its soft shade
     */
    private static function highlight(string $brand, StoreTemplate $template): array
    {
        if ($template === StoreTemplate::Butcher) {
            return ['#F3E6D3', '#F8EFE2'];
        }

        [$h, $s] = self::hsl($brand);

        // Green or near-grey brands keep the signature lime; any other hue gets a light shade of its own.
        if ($s < 0.18 || ($h >= 70 && $h <= 175)) {
            return [self::LIME, self::LIME_SOFT];
        }

        return [self::fromHsl($h, 0.85, 0.82), self::fromHsl($h, 0.8, 0.93)];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::normalise($hex) ?? '#000000', '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /**
     * @param  array<int, int>  $rgb
     */
    private static function hex(array $rgb): string
    {
        return '#'.strtoupper(implode('', array_map(fn (int $c) => str_pad(dechex(max(0, min(255, $c))), 2, '0', STR_PAD_LEFT), $rgb)));
    }

    /**
     * Hue (degrees), saturation and lightness (0–1).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function hsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (int $c) => $c / 255, self::rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d < 1e-9) {
            return [0.0, 0.0, $l];
        }

        $s = $d / (1 - abs(2 * $l - 1));
        $h = match (true) {
            $max === $r => 60 * fmod(($g - $b) / $d + 6, 6),
            $max === $g => 60 * (($b - $r) / $d + 2),
            default => 60 * (($r - $g) / $d + 4),
        };

        return [$h, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return self::hex([(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)]);
    }
}
