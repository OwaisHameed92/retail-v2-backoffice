<?php

namespace App\Domain\Leads\Support;

use Illuminate\Support\Str;

/**
 * Suggests a branch code (2–5 capital letters) from a shop name, like the ones staff type by hand:
 * "Leeds" → LDS, "Khan Mini Mart" → KMM, "Shop 2" → SHP. A code already taken gets a different last letter
 * (LDA, LDB…), then a fourth letter. The same rules run in the approval dialog (`suggestBranchCode` in TS).
 */
final class BranchCodeSuggester
{
    /**
     * @param  list<string>  $taken  Codes already used (upper case).
     */
    public static function suggest(string $name, array $taken = []): string
    {
        $base = self::base($name);

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        foreach (range('A', 'Z') as $letter) {
            $candidate = substr($base, 0, 2).$letter;
            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        foreach (range('A', 'Z') as $letter) {
            $candidate = substr($base, 0, 3).$letter;
            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        return substr($base, 0, 2).'XYZ';
    }

    private static function base(string $name): string
    {
        $words = array_values(array_filter(preg_split('/[^A-Z]+/', strtoupper(Str::ascii($name))) ?: []));

        if (count($words) >= 2) {
            $initials = implode('', array_map(fn (string $word) => $word[0], array_slice($words, 0, 3)));

            return strlen($initials) >= 2 ? $initials : 'SHP';
        }

        $word = $words[0] ?? '';

        if (strlen($word) < 2) {
            return $word === '' ? 'SHP' : $word.'X';
        }

        $code = $word[0];
        $rest = substr($word, 1);

        foreach (str_split($rest) as $char) {
            if (strlen($code) === 3) {
                break;
            }
            if (! in_array($char, ['A', 'E', 'I', 'O', 'U'], true)) {
                $code .= $char;
            }
        }

        foreach (str_split($rest) as $char) {
            if (strlen($code) === 3) {
                break;
            }
            if (in_array($char, ['A', 'E', 'I', 'O', 'U'], true)) {
                $code .= $char;
            }
        }

        return $code;
    }
}
