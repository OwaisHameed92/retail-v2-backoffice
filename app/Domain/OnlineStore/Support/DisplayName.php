<?php

namespace App\Domain\OnlineStore\Support;

/**
 * How the public store shows a till name (OS-11): a name typed in capitals on the till ("WALKERS CRISPS 6PK",
 * "FROZEN FOOD", "BIRMINGHAM") is shown in Title Case ("Walkers Crisps 6pk", "Frozen Food", "Birmingham"); a name
 * that already has lower-case letters is left as it is. Short words (and, of, the…) stay lower case inside a name,
 * and a few well-known abbreviations stay capitals. Display only: the stored name never changes.
 *
 *     DisplayName::of('COCA COLA 500ML'); // "Coca Cola 500ml"
 */
final class DisplayName
{
    private const SMALL = ['a', 'an', 'and', 'at', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with'];

    private const KEEP = ['UK', 'BBQ', 'XL', 'XXL', 'UHT', 'GB', 'NHS', 'PG', 'TV', 'USB', 'DIY', 'LED', 'AA', 'AAA', 'BOGOF', 'ID'];

    public static function of(string $name): string
    {
        $name = trim($name);
        $letters = (string) preg_replace('/[^\p{L}]/u', '', $name);

        if (mb_strlen($letters) < 3 || mb_strtoupper($letters) !== $letters) {
            return $name;
        }

        $words = preg_split('/(\s+|(?<=[\/(\-&]))/u', mb_strtolower($name), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        $first = true;

        foreach ($words as $word) {
            if ($word === '' || preg_match('/^\s+$/u', $word) === 1) {
                $out .= $word;

                continue;
            }

            $out .= self::word($word, $first);
            $first = false;
        }

        return $out;
    }

    private static function word(string $word, bool $first): string
    {
        $bare = (string) preg_replace('/[^\p{L}]/u', '', $word);

        if (in_array(mb_strtoupper($bare), self::KEEP, true) && mb_strlen($bare) === mb_strlen((string) preg_replace('/[^\p{L}\p{N}]/u', '', $word))) {
            return mb_strtoupper($word);
        }

        if (! $first && in_array($word, self::SMALL, true)) {
            return $word;
        }

        // Upper-case the first letter only when the word starts with one ("6pk" stays "6pk").
        return (string) preg_replace_callback('/^([^\p{L}\p{N}]*)(\p{L})/u', fn (array $m) => $m[1].mb_strtoupper($m[2]), $word);
    }
}
