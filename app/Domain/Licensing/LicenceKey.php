<?php

namespace App\Domain\Licensing;

use App\Domain\Licensing\Exceptions\InvalidLicenceKey;
use LogicException;
use RuntimeException;
use SensitiveParameter;

/**
 * A licence key: `SSP-XXXX-XXXX-XXXX-XXXX`. The 16 characters after the prefix are Crockford base32
 * (0-9, A-Z without I, L, O, U); the first 15 are random, the 16th is a Luhn mod 32 check character so the till
 * can catch typos offline. Algorithm and test vectors: docs/specs/licence-api-v1.md ("Key format").
 *
 * A key is a secret. The portal stores only {@see hash()} (HMAC-SHA256 keyed with APP_KEY) and {@see last4()}.
 * This object never prints the key by accident: no __toString, redacted in dumps, cannot be serialised.
 */
final class LicenceKey
{
    public const PREFIX = 'SSP';

    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Characters after the prefix, including the check character. */
    public const LENGTH = 16;

    /** Shown instead of the hidden characters, e.g. "SSP-••••-••••-••••-B6WN". */
    public const MASK = '••••';

    private function __construct(#[SensitiveParameter] private readonly string $body) {}

    /** A new random key (15 random characters from random_bytes + the check character). */
    public static function generate(): self
    {
        $payload = '';

        // 256 is a multiple of 32, so the low 5 bits of each byte are uniform.
        foreach (str_split(random_bytes(self::LENGTH - 1)) as $byte) {
            $payload .= self::ALPHABET[ord($byte) & 31];
        }

        return new self($payload.self::checkCharacter($payload));
    }

    /**
     * Parse what a person typed or pasted. Accepts any case, spaces and dashes, with or without the "SSP"
     * prefix, and the usual Crockford confusions (O → 0, I and L → 1).
     *
     * @throws InvalidLicenceKey
     */
    public static function parse(#[SensitiveParameter] string $input): self
    {
        $body = self::normalise($input);

        if (strlen($body) !== self::LENGTH || strspn($body, self::ALPHABET) !== self::LENGTH) {
            throw InvalidLicenceKey::format();
        }

        if (! self::hasValidCheckCharacter($body)) {
            throw InvalidLicenceKey::checkCharacter();
        }

        return new self($body);
    }

    public static function tryParse(#[SensitiveParameter] string $input): ?self
    {
        try {
            return self::parse($input);
        } catch (InvalidLicenceKey) {
            return null;
        }
    }

    public static function isValid(#[SensitiveParameter] string $input): bool
    {
        return self::tryParse($input) !== null;
    }

    /**
     * Upper-case, drop spaces/dashes/underscores/dots, map O → 0 and I, L → 1, drop the "SSP" prefix when the
     * rest is a full key. The result is not validated.
     */
    public static function normalise(#[SensitiveParameter] string $input): string
    {
        $body = strtr(strtoupper((string) preg_replace('/[\s\-_.]+/u', '', $input)), ['O' => '0', 'I' => '1', 'L' => '1']);

        if (strlen($body) === strlen(self::PREFIX) + self::LENGTH && str_starts_with($body, self::PREFIX)) {
            $body = substr($body, strlen(self::PREFIX));
        }

        return $body;
    }

    /**
     * Luhn mod 32 check character for the first 15 characters (see docs/specs/licence-api-v1.md).
     *
     * @throws InvalidLicenceKey when the payload is not 15 alphabet characters
     */
    public static function checkCharacter(string $payload): string
    {
        if (strlen($payload) !== self::LENGTH - 1 || strspn($payload, self::ALPHABET) !== self::LENGTH - 1) {
            throw InvalidLicenceKey::format();
        }

        $sum = self::luhnSum($payload, doubleFirst: true);

        return self::ALPHABET[(32 - ($sum % 32)) % 32];
    }

    /** Whether a 16-character normalised body ends with the right check character. */
    public static function hasValidCheckCharacter(string $body): bool
    {
        return strlen($body) === self::LENGTH
            && strspn($body, self::ALPHABET) === self::LENGTH
            && self::luhnSum($body, doubleFirst: false) % 32 === 0;
    }

    /** "SSP-7K2Q-9DMF-3XRA-P8TN" */
    public function formatted(): string
    {
        return self::PREFIX.'-'.implode('-', str_split($this->body, 4));
    }

    /** The 16 characters after the prefix, as hashed. */
    public function body(): string
    {
        return $this->body;
    }

    /** Last 4 characters (includes the check character). Safe to store and show. */
    public function last4(): string
    {
        return substr($this->body, -4);
    }

    /** HMAC-SHA256 (hex) of the normalised key, keyed with the current APP_KEY. */
    public function hash(): string
    {
        return self::hashWith($this->body, self::appKeys()[0]);
    }

    /**
     * Hashes under the current and previous APP_KEYs (`APP_PREVIOUS_KEYS`), so keys issued before an APP_KEY
     * rotation are still found. Look up with `whereIn('key_hash', $key->hashCandidates())`.
     *
     * @return list<string>
     */
    public function hashCandidates(): array
    {
        return array_values(array_unique(array_map(fn (string $secret) => self::hashWith($this->body, $secret), self::appKeys())));
    }

    /** Whether this key is the one behind a stored hash (constant-time compare). */
    public function matches(string $storedHash): bool
    {
        foreach ($this->hashCandidates() as $candidate) {
            if (hash_equals($storedHash, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** "SSP-••••-••••-••••-B6WN" */
    public static function mask(string $last4): string
    {
        return self::PREFIX.'-'.self::MASK.'-'.self::MASK.'-'.self::MASK.'-'.$last4;
    }

    /** HMAC-SHA256 hex of a normalised body under one raw secret. Pure; used by hash() and the tests. */
    public static function hashWith(#[SensitiveParameter] string $body, #[SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => self::mask($this->last4())];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Licence keys cannot be serialised.');
    }

    /**
     * Luhn mod N (N = 32) running sum from the rightmost character. `doubleFirst` doubles the rightmost
     * character (generating a check character); otherwise the rightmost is the check character and is not doubled.
     */
    private static function luhnSum(string $chars, bool $doubleFirst): int
    {
        $factor = $doubleFirst ? 2 : 1;
        $sum = 0;

        for ($i = strlen($chars) - 1; $i >= 0; $i--) {
            $addend = $factor * (int) strpos(self::ALPHABET, $chars[$i]);
            $sum += intdiv($addend, 32) + ($addend % 32);
            $factor = $factor === 2 ? 1 : 2;
        }

        return $sum;
    }

    /**
     * Raw APP_KEY secrets: current first, then APP_PREVIOUS_KEYS.
     *
     * @return non-empty-list<string>
     */
    private static function appKeys(): array
    {
        $keys = array_merge([config('app.key')], (array) config('app.previous_keys', []));
        $secrets = [];

        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $secrets[] = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
        }

        if ($secrets === [] || $secrets[0] === '') {
            throw new RuntimeException('APP_KEY is not set: licence keys cannot be hashed.');
        }

        return $secrets;
    }
}
