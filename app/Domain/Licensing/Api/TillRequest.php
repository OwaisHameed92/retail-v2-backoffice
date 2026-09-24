<?php

namespace App\Domain\Licensing\Api;

use App\Domain\Licensing\Api\Support\DeviceHash;
use App\Domain\Licensing\LicenceKey;
use Carbon\CarbonImmutable;

/**
 * What a till sent to the licence API, already validated. The key is a {@see LicenceKey} (never printed).
 */
final readonly class TillRequest
{
    public function __construct(
        public LicenceKey $key,
        public string $deviceId,
        public ?string $deviceName = null,
        public ?string $appVersion = null,
        public ?string $os = null,
        public ?string $ip = null,
        public ?string $tokenId = null,
        public ?CarbonImmutable $lastSaleAt = null,
        public ?CarbonImmutable $requestedAt = null,
    ) {}

    /**
     * Safe facts about the calling PC for alerts and device history: no key, only the end of the device id.
     *
     * @return array<string, string|null>
     */
    public function describePc(): array
    {
        return [
            'deviceName' => $this->deviceName,
            'deviceIdEnding' => DeviceHash::ending($this->deviceId),
            'ip' => $this->ip,
            'appVersion' => $this->appVersion,
            'os' => $this->os,
        ];
    }
}
