<?php

namespace App\Domain\Licensing\Api;

use App\Domain\Licensing\Api\Support\DeviceHash;
use Carbon\CarbonImmutable;

/**
 * The calling till (one PC install) as a licence API request described it, already validated
 * (contract v1.4.1 §17.15). Never holds the licence key.
 */
final readonly class TillRequest
{
    /**
     * @param  array{name?: string, version?: string, architecture?: string|null}|null  $os
     * @param  list<string>  $trustedKids
     * @param  list<string>  $approverKids
     * @param  array{companyId: string, branchId: string, registerId: string}|null  $existingIds
     */
    public function __construct(
        public string $installId,
        public ?string $installCode = null,
        public ?string $deviceName = null,
        public ?string $appVersion = null,
        public ?array $os = null,
        public ?string $ip = null,
        public ?CarbonImmutable $tillClockUtc = null,
        public array $trustedKids = [],
        public array $approverKids = [],
        public ?array $existingIds = null,
        public ?CarbonImmutable $clockWatermarkUtc = null,
        public ?bool $locked = null,
        public ?string $lockReason = null,
        /** validate: the till's last successful sync, null = never (module 2.1: "the till reports no sync key"). */
        public ?CarbonImmutable $lastSyncAt = null,
    ) {}

    /** "Windows 11 Pro 10.0.26200 x64", or null. */
    public function osLabel(): ?string
    {
        $label = trim(implode(' ', array_filter([$this->os['name'] ?? null, $this->os['version'] ?? null, $this->os['architecture'] ?? null])));

        return $label === '' ? null : $label;
    }

    /**
     * Safe facts about the calling PC for alerts and device history: no key, only the end of the install id.
     *
     * @return array<string, string|null>
     */
    public function describePc(): array
    {
        return [
            'deviceName' => $this->deviceName,
            'installCode' => $this->installCode,
            'deviceIdEnding' => DeviceHash::ending($this->installId),
            'ip' => $this->ip,
            'appVersion' => $this->appVersion,
            'os' => $this->osLabel(),
        ];
    }
}
