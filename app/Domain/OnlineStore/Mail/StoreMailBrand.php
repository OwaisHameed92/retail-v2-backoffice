<?php

namespace App\Domain\OnlineStore\Mail;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\StoreAddress;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;

/**
 * How a store's customer emails look (OS-10): its name, logo (absolute URL), brand colour with a readable text colour
 * on it, a link colour that is readable on white, a light tint for panels, and the shop's address and phone for the
 * footer. Plain values only, so a queued email holds no model.
 *
 *     $brand = StoreMailBrand::for($store, $company, $order->branch);
 */
final readonly class StoreMailBrand
{
    private const DARK_TEXT = '#111827';

    private const WHITE = '#FFFFFF';

    public function __construct(
        public string $name,
        public string $colour,
        public string $onColour,
        public string $linkColour,
        public string $tint,
        public ?string $logoUrl = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $storeUrl = null,
    ) {}

    public static function for(OnlineStore $store, Company $company, ?Branch $shop = null): self
    {
        $address = $shop === null ? null : self::join([$shop->address, $shop->town, $shop->postcode]);

        return self::make(
            name: $company->name,
            colour: $store->brand_colour ?? $store->template->defaultBrand(),
            logoUrl: self::absolute(StoreImages::url($store->logo_path)),
            address: $address,
            phone: self::clean($shop?->phone) ?? self::clean($company->phone),
            storeUrl: $store->is_live ? StoreAddress::of($store) : null,
        );
    }

    /** The look of a business without a store row (or an email queued before OS-10): its name on forest green. */
    public static function plain(string $name): self
    {
        return self::make($name, '#0B4A34');
    }

    public static function make(string $name, ?string $colour, ?string $logoUrl = null, ?string $address = null, ?string $phone = null, ?string $storeUrl = null): self
    {
        $colour = self::hex($colour) ?? '#0B4A34';

        return new self(
            name: $name,
            colour: $colour,
            onColour: self::contrast($colour, self::WHITE) >= self::contrast($colour, self::DARK_TEXT) ? self::WHITE : self::DARK_TEXT,
            linkColour: self::readableOnWhite($colour),
            tint: self::mix($colour, self::WHITE, 0.08),
            logoUrl: $logoUrl,
            address: $address,
            phone: $phone,
            storeUrl: $storeUrl,
        );
    }

    /** WCAG contrast ratio of two "#RRGGBB" colours (1 to 21). */
    public static function contrast(string $a, string $b): float
    {
        [$l1, $l2] = [self::luminance($a), self::luminance($b)];

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** The colour, darkened until it reads on white (4.5:1). */
    private static function readableOnWhite(string $colour): string
    {
        $out = $colour;

        for ($i = 1; $i <= 20 && self::contrast($out, self::WHITE) < 4.5; $i++) {
            $out = self::mix($colour, '#000000', 1 - $i * 0.05);
        }

        return $out;
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** `$weight` of $a and the rest of $b. */
    private static function mix(string $a, string $b, float $weight): string
    {
        $x = self::rgb($a);
        $y = self::rgb($b);

        return sprintf('#%02X%02X%02X', ...array_map(fn (int $i) => (int) round($x[$i] * $weight + $y[$i] * (1 - $weight)), [0, 1, 2]));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    private static function hex(?string $colour): ?string
    {
        return is_string($colour) && preg_match('/^#[0-9A-Fa-f]{6}$/', $colour) === 1 ? strtoupper($colour) : null;
    }

    private static function absolute(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    /**
     * @param  list<string|null>  $parts
     */
    private static function join(array $parts): ?string
    {
        $parts = array_values(array_filter(array_map(self::clean(...), $parts)));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
