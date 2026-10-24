<?php

namespace App\Domain\OnlineStore\Mail;

/**
 * A shop's customer emails never go out in the platform's name (OS-10, owner): "Switch & Save", "Switch and Save" or
 * "SSPOS" is refused as a store's sender name, and ignored if one is ever stored.
 */
final class SenderName
{
    public static function isPlatform(string $name): bool
    {
        $plain = strtolower(html_entity_decode($name));

        return preg_match('/switch\s*(?:&|\+|and|n)?\s*save|sspos/', $plain) === 1;
    }

    /** A name fit for a From header: one line, no control characters. */
    public static function wellFormed(string $name): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $name) !== 1;
    }
}
