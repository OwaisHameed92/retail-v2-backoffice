<?php

namespace App\Domain\Licensing\Support;

use App\Domain\Licensing\Api\Support\DeviceHistory;
use App\Domain\Licensing\Models\Licence;
use Carbon\CarbonImmutable;

/**
 * Releases a licence key from its PC (contract v1.3.1 §17.15.3 "Release"): the binding is cleared, so the key
 * can be activated on another PC, and the old install is remembered as released, so its next
 * `licence/validate` answers `released` and it locks. Used by the admin Release action, `devices/deactivate`
 * and "Reissue key". The caller locks the row, saves nothing else and audits.
 */
final class InstallRelease
{
    public function __construct(private readonly DeviceHistory $devices) {}

    /**
     * @return array{device_id: string|null, device_name: string|null, install_code: string|null, bound_at: string|null}
     *                                                                                                                   The binding before, for the audit row.
     */
    public function apply(Licence $licence, CarbonImmutable $now): array
    {
        $before = [
            'device_id' => $licence->device_id,
            'device_name' => $licence->device_name,
            'install_code' => $licence->install_code,
            'bound_at' => $licence->bound_at?->toIso8601String(),
        ];

        if ($licence->device_id !== null) {
            $this->devices->markReleased($licence, $licence->device_id, $licence->device_name, $now);
        }

        $licence->device_id = null;
        $licence->device_name = null;
        $licence->install_code = null;
        $licence->bound_at = null;
        $licence->token_sha256 = null;
        $licence->token_kid = null;
        $licence->token_fingerprint = null;
        $licence->lock_locked = null;
        $licence->lock_reason = null;
        $licence->save();

        return $before;
    }

    /**
     * @return array{device_id: null, device_name: null, install_code: null, bound_at: null}
     */
    public static function after(): array
    {
        return ['device_id' => null, 'device_name' => null, 'install_code' => null, 'bound_at' => null];
    }
}
