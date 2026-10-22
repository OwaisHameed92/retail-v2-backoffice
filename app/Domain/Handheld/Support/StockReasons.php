<?php

namespace App\Domain\Handheld\Support;

/**
 * The handheld's free-text reasons ↔ the fixed codes of contract proposal pda-stock-drafts: adjustments
 * (`StockAdjustmentReason`) and return lines (`PurchaseReturnLine.reason`). "Damaged", "damaged" or "DAMAGED" is the
 * code; any other text is `other` with the text kept as the note the till shows.
 */
final class StockReasons
{
    public const ADJUSTMENT = ['damaged' => 'Damaged', 'expired' => 'Expired', 'theft' => 'Theft', 'countCorrection' => 'Count correction', 'other' => 'Other'];

    public const RETURN = ['damaged' => 'Damaged', 'wrong' => 'Wrong item', 'expired' => 'Expired', 'recalled' => 'Recalled', 'other' => 'Other'];

    /**
     * @param  array<string, string>  $list  code => label
     * @return array{0: string|null, 1: string} the code (null when no text) and the note ("" when the text was a code)
     */
    public static function parse(?string $text, array $list): array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return [null, ''];
        }

        $key = self::key($text);

        foreach ($list as $code => $label) {
            if ($key === self::key($code) || $key === self::key($label)) {
                return [$code, ''];
            }
        }

        return ['other', mb_substr($text, 0, 255)];
    }

    /**
     * What the handheld shows: the operator's own text, else the code's label.
     *
     * @param  array<string, string>  $list
     */
    public static function text(?string $code, ?string $note, array $list): ?string
    {
        $note = trim((string) $note);

        return $note !== '' ? $note : ($code === null ? null : ($list[$code] ?? $code));
    }

    private static function key(string $value): string
    {
        return (string) preg_replace('/[^a-z]/', '', mb_strtolower($value));
    }
}
