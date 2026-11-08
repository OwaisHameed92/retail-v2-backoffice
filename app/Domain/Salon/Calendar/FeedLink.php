<?php

namespace App\Domain\Salon\Calendar;

use App\Domain\Salon\Models\SalonCalendarFeed;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use SensitiveParameter;

/**
 * The one-time reply after a feed is turned on or given a new address: the https address, the same as `webcal://`
 * (opens the phone's calendar app directly) and an inline SVG QR code of it, made here (no outside service ever sees
 * the address). Built from the plain token, which only this reply carries.
 */
final class FeedLink
{
    /**
     * @return array{url: string, webcalUrl: string, qrSvg: string}
     */
    public static function reply(SalonCalendarFeed $feed, #[SensitiveParameter] string $token): array
    {
        $url = route('salon.ical', ['branchId' => $feed->branch_id, 'userId' => $feed->user_id, 'feedToken' => $token]);

        return [
            'url' => $url,
            'webcalUrl' => (string) preg_replace('#^https?://#', 'webcal://', $url),
            'qrSvg' => self::qrSvg($url),
        ];
    }

    private static function qrSvg(#[SensitiveParameter] string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($url);

        return trim((string) preg_replace('/^<\?xml.*?\?>/', '', $svg));
    }
}
