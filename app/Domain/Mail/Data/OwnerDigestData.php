<?php

namespace App\Domain\Mail\Data;

/**
 * The 07:00 daily alert digest of one user (module 7.8): one section per alert type with something to say, and the
 * morning summary (module 6.3) when the user has it on and there was news: SummaryView + narrative, url,
 * unsubscribeUrl.
 */
final readonly class OwnerDigestData
{
    /**
     * @param  string  $day  London day the digest is for (Y-m-d)
     * @param  list<array{type: string, title: string, summary: string, items: list<string>, more: int, url: string, unsubscribeUrl: string}>  $sections
     * @param  array<string, mixed>|null  $summary
     */
    public function __construct(
        public string $businessName,
        public string $recipientName,
        public string $day,
        public array $sections,
        public string $settingsUrl,
        /** Signed link that turns every daily-digest choice off. */
        public string $unsubscribeUrl,
        public ?string $companyId = null,
        public ?array $summary = null,
    ) {}
}
