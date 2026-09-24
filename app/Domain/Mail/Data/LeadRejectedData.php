<?php

namespace App\Domain\Mail\Data;

/**
 * A polite "we cannot offer a trial right now" to a prospect (module 1.6). Never carries the internal reason.
 */
final readonly class LeadRejectedData
{
    public function __construct(
        public string $contactName,
        public string $businessName,
    ) {}
}
