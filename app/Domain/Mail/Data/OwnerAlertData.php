<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonImmutable;

/**
 * An urgent owner alert (module 7.8): a till offline or a shop's sync failing / stalled, and when it cleared.
 */
final readonly class OwnerAlertData
{
    /**
     * @param  string  $type  AlertType value (tillOffline | syncFailing)
     * @param  string  $problem  LicenceAlertType value (tillOffline | syncFailing | syncStalled)
     */
    public function __construct(
        public string $businessName,
        public string $recipientName,
        public string $type,
        public string $problem,
        public string $shopName,
        public ?string $tillName,
        public ?string $summary,
        public CarbonImmutable $since,
        public string $url,
        public string $unsubscribeUrl,
        public string $settingsUrl,
        public ?CarbonImmutable $resolvedAt = null,
        public ?string $companyId = null,
    ) {}

    /** "Till 1 at Leeds is offline", "Sync is failing at Leeds", "Till 1 at Leeds is back online"… */
    public function headline(): string
    {
        $till = ($this->tillName ?? 'A till').' at '.$this->shopName;

        return match ($this->problem) {
            'tillOffline' => $this->resolvedAt === null ? $till.' is offline' : $till.' is back online',
            'syncStalled' => $this->resolvedAt === null ? 'Sync has stalled at '.$this->shopName : 'Sync is running again at '.$this->shopName,
            default => $this->resolvedAt === null ? 'Sync is failing at '.$this->shopName : 'Sync is working again at '.$this->shopName,
        };
    }
}
