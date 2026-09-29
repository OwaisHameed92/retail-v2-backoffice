<?php

namespace App\Domain\TillData\Sync;

/**
 * The contract's settings deny-list (v1.4 §10.3, §10.7; `samples/settings-local-only.json`, the till's
 * `SettingSyncPolicy.cs`): a Setting row that never leaves the till and is never applied from a pull. The portal
 * never stores one (a push carrying one is acknowledged and dropped, its value never logged) and never sends one.
 *
 * Local only when: the scope is `register`; the key is one of LOCAL_KEYS (catalogue keys the rules below do not
 * catch: register-scope and secret-type settings); it starts with a LOCAL_PREFIXES entry; it contains a
 * SECRET_WORDS entry; or it ends with a SECRET_ENDINGS / BOOKKEEPING_ENDINGS entry. A test keeps these lists equal
 * to the contract file.
 */
final class SettingSyncPolicy
{
    public const LOCAL_SCOPES = ['register'];

    public const LOCAL_PREFIXES = [
        'licence.', 'install.', 'sync.', 'server.', 'update.', 'backup.', 'devices.', 'printers.', 'payments.terminal_',
        'payments.dna_', 'payments.dojo_', 'messaging.smtp_', 'messaging.whatsapp_gateway_',
    ];

    public const SECRET_WORDS = ['secret', 'passphrase', 'thumbprint', 'api_key', 'private_key', 'signing_key'];

    public const SECRET_ENDINGS = ['password', '_key', '.key', '_hash', 'token'];

    public const BOOKKEEPING_ENDINGS = ['_utc'];

    /** `localOnlyKeys` of the contract file that no rule above catches. */
    public const LOCAL_KEYS = [
        'display.button_size', 'display.font_scale', 'display.fullscreen_kiosk', 'display.language',
        'display.layout_profile', 'display.start_with_windows', 'display.text_size', 'display.theme',
        'payments.offline_card_mode', 'payments.open_drawer_on_card', 'payments.open_drawer_on_cash',
        'receipt.number_prefix', 'receipt.number_start', 'retention.clock_check_enabled',
        'retention.clock_tolerance_minutes', 'till.audio_cues_enabled', 'till.audio_cues_set', 'till.audio_cues_volume',
        'till.category_full_panel', 'till.category_tile_height', 'till.category_tile_width', 'till.keyboard_macros',
        'till_ease.big_text', 'till_ease.layout_profile', 'till_ease.scan_feedback_enabled',
    ];

    public static function isLocalOnly(mixed $scope, mixed $key): bool
    {
        if (! is_string($scope) || ! is_string($key) || in_array(strtolower($scope), self::LOCAL_SCOPES, true)) {
            return true;
        }

        $key = strtolower($key);

        if (in_array($key, self::LOCAL_KEYS, true)) {
            return true;
        }

        foreach (self::LOCAL_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        foreach (self::SECRET_WORDS as $word) {
            if (str_contains($key, $word)) {
                return true;
            }
        }

        foreach ([...self::SECRET_ENDINGS, ...self::BOOKKEEPING_ENDINGS] as $ending) {
            if (str_ends_with($key, $ending)) {
                return true;
            }
        }

        return false;
    }
}
