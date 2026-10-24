<?php

namespace App\Domain\OnlineStore\Mail\Sending;

use App\Domain\OnlineStore\Models\OnlineStoreMailSettings;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns an SMTP failure into plain words for the shop (OS-10), e.g. "Your email server did not accept the username
 * or password." Never contains the password (removed again, also base64-encoded, from any server reply that is
 * quoted) and is at most 300 characters, so it is safe to store, log and show.
 */
final class SmtpErrorText
{
    public static function for(Throwable $error, OnlineStoreMailSettings $settings): string
    {
        if ($error instanceof StoreSmtpUnavailable) {
            return self::clean($error->getMessage(), $settings);
        }

        $raw = $error->getMessage();
        $lower = Str::lower($raw);
        $server = "{$settings->smtp_host} on port {$settings->smtp_port}";

        $text = match (true) {
            str_contains($lower, 'authenticat') || preg_match('/\b(535|534|530)\b/', $raw) === 1 => 'Your email server did not accept the username or password. Check them, or create an app password if your provider asks for one.',
            str_contains($lower, 'timed out') || str_contains($lower, 'timeout') => "No answer from {$server}. Check the server name, port and encryption, and that the server accepts connections from outside.",
            str_contains($lower, 'connection could not be established') || str_contains($lower, 'connection refused') || str_contains($lower, 'unable to connect') => "We could not connect to {$server}. Check the server name, port and encryption.",
            str_contains($lower, 'starttls') || str_contains($lower, 'ssl') || str_contains($lower, 'tls') || str_contains($lower, 'certificate') || str_contains($lower, 'crypto') => 'The secure connection to your email server failed. Try TLS on port 587 or SSL on port 465.',
            preg_match('/\b(550|551|553|554)\b/', $raw) === 1 && (str_contains($lower, 'sender') || str_contains($lower, 'from') || str_contains($lower, 'mail from')) => "Your email server refused to send from {$settings->smtp_from_address}. Use an address that account is allowed to send from.",
            default => 'Your email server said: '.self::serverReply($raw),
        };

        return self::clean($text, $settings);
    }

    /** The interesting part of a Symfony SMTP error: the server's own message, else the whole text. */
    private static function serverReply(string $raw): string
    {
        if (preg_match('/with message "([^"]+)"/', $raw, $m) === 1) {
            return trim($m[1]);
        }

        return trim($raw);
    }

    private static function clean(string $text, OnlineStoreMailSettings $settings): string
    {
        $password = (string) $settings->smtp_password;

        if ($password !== '') {
            $text = str_replace([$password, base64_encode($password)], '••••', $text);
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return Str::limit(trim($text), 300);
    }
}
