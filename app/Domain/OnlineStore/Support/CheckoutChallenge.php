<?php

namespace App\Domain\OnlineStore\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * A small proof of work on the checkout (audit OSA-1), instead of a third-party CAPTCHA: no tracker, nothing sent
 * anywhere else. The page gets a token (store id, time, a random nonce, signed with the app key); the browser finds a
 * number `n` such that sha256(token + ":" + n) starts with `online_store.challenge_zeros` hex zeros (about 4,000
 * tries for 3, a fraction of a second) and sends it with the order. Refused when the signature is wrong, it is
 * another store's, the page was open for less than `challenge_min_seconds` (a bot posting at once) or more than
 * 3 hours, or the token was used already (each one places one order). Off with `online_store.checkout_challenge`.
 */
final class CheckoutChallenge
{
    public const MAX_AGE_SECONDS = 10800;

    /**
     * @return array{token: string, zeros: int}|null null while switched off
     */
    public static function issue(string $storeId, ?int $now = null): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $body = rtrim(strtr(base64_encode((string) json_encode(['s' => $storeId, 't' => $now ?? CarbonImmutable::now()->getTimestamp(), 'n' => bin2hex(random_bytes(12))])), '+/', '-_'), '=');

        return ['token' => $body.'.'.self::mac($body), 'zeros' => self::zeros()];
    }

    public static function verify(string $storeId, string $token, string $answer, ?int $now = null): bool
    {
        if (! self::enabled()) {
            return true;
        }

        [$body, $mac] = explode('.', $token, 2) + [1 => ''];
        $data = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);
        $now ??= CarbonImmutable::now()->getTimestamp();

        if ($body === '' || ! hash_equals(self::mac($body), $mac) || ! is_array($data) || ($data['s'] ?? null) !== $storeId
            || preg_match('/^\d{1,9}$/', $answer) !== 1 || ! str_starts_with(hash('sha256', $token.':'.$answer), str_repeat('0', self::zeros()))) {
            return false;
        }

        $age = $now - (int) ($data['t'] ?? 0);

        if ($age < max(0, (int) config('online_store.challenge_min_seconds', 2)) || $age > self::MAX_AGE_SECONDS) {
            return false;
        }

        return Cache::add('online-store-challenge:'.hash('sha256', $token), true, self::MAX_AGE_SECONDS);
    }

    /** The answer a browser would find (tests, and the reference for the page's code). */
    public static function solve(string $token): string
    {
        $prefix = str_repeat('0', self::zeros());

        for ($n = 0; ; $n++) {
            if (str_starts_with(hash('sha256', $token.':'.$n), $prefix)) {
                return (string) $n;
            }
        }
    }

    public static function enabled(): bool
    {
        return (bool) config('online_store.checkout_challenge', true);
    }

    private static function zeros(): int
    {
        return min(5, max(1, (int) config('online_store.challenge_zeros', 3)));
    }

    private static function mac(string $body): string
    {
        return hash_hmac('sha256', 'online-store-checkout|'.$body, (string) config('app.key'));
    }
}
