<?php

namespace App\Domain\Security\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;
use Throwable;

/**
 * RFC 6238 one-time codes (6 digits, 30 seconds, SHA-1: what every authenticator app expects) and the set-up QR.
 * One step either side of now is accepted for clock drift; a step is never accepted twice (`$lastStep`).
 */
final class Totp
{
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa = new Google2FA) {}

    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * @return int|null The accepted time step, or null when the code does not match (or was already used).
     */
    public function verify(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code, ?int $lastStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        try {
            // Always passing a previous step makes the library return the matched step (an int) instead of true.
            $step = $this->google2fa->verifyKeyNewer($secret, $code, $lastStep ?? 0, self::WINDOW);
        } catch (Throwable) {
            return null;
        }

        return is_int($step) ? $step : null;
    }

    /** The current code, for tests and local tooling. */
    public function current(#[SensitiveParameter] string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }

    public function otpauthUrl(string $issuer, string $account, #[SensitiveParameter] string $secret): string
    {
        return $this->google2fa->getQRCodeUrl($issuer, $account, $secret);
    }

    /** The otpauth URL as an inline SVG QR code (no external service ever sees the secret). */
    public function qrSvg(#[SensitiveParameter] string $otpauthUrl): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));
        $svg = $writer->writeString($otpauthUrl);

        return trim((string) preg_replace('/^<\?xml.*?\?>/', '', $svg));
    }

    /** "ABCD EFGH …" for typing the key by hand. */
    public static function formatSecret(#[SensitiveParameter] string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }
}
