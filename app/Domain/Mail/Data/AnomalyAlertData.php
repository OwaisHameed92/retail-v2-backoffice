<?php

namespace App\Domain\Mail\Data;

/**
 * A high-severity unusual-activity alert (module 6.6), emailed straight away to users who chose it.
 */
final readonly class AnomalyAlertData
{
    /**
     * @param  array<string, string>  $facts  label => value (with the usual value when there is one)
     */
    public function __construct(
        public string $businessName,
        public string $recipientName,
        public string $title,
        public string $summary,
        public string $shopName,
        public string $kindLabel,
        public array $facts,
        public string $url,
        public string $unsubscribeUrl,
        public string $settingsUrl,
        public ?string $companyId = null,
    ) {}
}
