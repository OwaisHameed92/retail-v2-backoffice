<?php

namespace App\Domain\OnlineStore\Support;

/**
 * The rules for a store's address (`/{slug}`): 3 to 40 lower-case letters, digits and single hyphens, starting and
 * ending with a letter or digit; not a reserved word (our own pages and words that could pass for us). Unique across
 * every business (unique index `online_stores_slug_unique`).
 */
final class StoreSlug
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9]|-(?!-)){1,38}[a-z0-9]$/';

    public const RESERVED = [
        'about', 'account', 'accounts', 'admin', 'administrator', 'api', 'app', 'apps', 'assets', 'auth', 'basket', 'billing',
        'blog', 'build', 'cart', 'checkout', 'collect', 'contact', 'cookies', 'css', 'dashboard', 'dev', 'docs', 'download',
        'email', 'favicon', 'files', 'fonts', 'ftp', 'help', 'home', 'images', 'img', 'js', 'legal', 'login', 'logout', 'mail',
        'media', 'new', 'order', 'orders', 'partner', 'partners', 'password', 'pda', 'portal', 'pos', 'privacy', 'register',
        'reset', 'robots', 'root', 'search', 'security', 'settings', 'shop', 'shops', 'signin', 'signup', 'sitemap', 'smtp',
        'sspos', 'staging', 'static', 'status', 'storage', 'store', 'stores', 'support', 'switch-and-save', 'switchandsave',
        'sync', 'system', 'terms', 'test', 'up', 'webhook', 'webhooks', 'www',
    ];

    /** Trimmed and lower-cased (an address is case-blind). */
    public static function normalise(string $slug): string
    {
        return strtolower(trim($slug));
    }

    /** The reason a (normalised) slug is refused, or null when its shape is fine. Uniqueness is checked by the caller. */
    public static function problem(string $slug): ?string
    {
        return match (true) {
            strlen($slug) < 3 || strlen($slug) > 40 => 'Use 3 to 40 characters for the store address.',
            preg_match(self::PATTERN, $slug) !== 1 => 'Use lower-case letters, numbers and single hyphens, starting and ending with a letter or number.',
            in_array($slug, self::RESERVED, true) => 'That address is reserved. Choose another one.',
            default => null,
        };
    }
}
