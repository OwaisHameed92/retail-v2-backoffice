<?php

namespace App\Domain\Ai\MorningSummary\Support;

/**
 * The guard on the morning summary's AI paragraph (module 6.3): every number the model wrote must appear in the
 * facts it was given (compared after dropping thousands separators, £, % and trailing zeros, so "£1,234.50" in the
 * facts allows "1234.5"), the text must be plain, and short. Anything else is dropped and the email goes without it.
 */
final class NarrativeCheck
{
    public const MAX_CHARS = 900;

    public const MAX_SENTENCES = 6;

    /**
     * @param  array<string, mixed>  $facts
     * @return string|null the cleaned paragraph, or null when it must not be shown
     */
    public static function clean(string $text, array $facts): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        if ($text === '' || mb_strlen($text) > self::MAX_CHARS || self::sentences($text) > self::MAX_SENTENCES
            || preg_match('/https?:|www\.|[*#`<>\[\]{}|]/i', $text) === 1) {
            return null;
        }

        return self::numbersAllowed($text, $facts) ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function numbersAllowed(string $text, array $facts): bool
    {
        $allowed = array_flip(self::numbers((string) json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

        foreach (self::numbers($text) as $number) {
            if (! isset($allowed[$number])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every number in a text, normalised: "£1,234.50" → "1234.5", "07" → "7", "12.0%" → "12".
     *
     * @return list<string>
     */
    public static function numbers(string $text): array
    {
        preg_match_all('/\d+(?:,\d{3})*(?:\.\d+)?/', $text, $matches);

        return array_values(array_unique(array_map(function (string $n) {
            $n = str_replace(',', '', $n);

            if (str_contains($n, '.')) {
                $n = rtrim(rtrim($n, '0'), '.');
            }

            $n = ltrim($n, '0');

            return $n === '' || str_starts_with($n, '.') ? '0'.$n : $n;
        }, $matches[0])));
    }

    private static function sentences(string $text): int
    {
        return count(array_filter(preg_split('/(?<=[.!?])\s+/u', $text) ?: []));
    }
}
