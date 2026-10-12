<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

/**
 * A partner (Zivaro) sent a business for review, sent a rejected one again, or cancelled one already approved,
 * summarised for staff (AdminPartnerRequestMail). Personal details go in the email only, not the email log.
 */
final readonly class PartnerRequestAlertData
{
    public const NEW = 'new';

    public const SENT_AGAIN = 'sentAgain';

    public const PARTNER_CANCELLED = 'partnerCancelled';

    public function __construct(
        /** self::NEW | self::SENT_AGAIN | self::PARTNER_CANCELLED */
        public string $event,
        public string $requestId,
        public string $businessName,
        public string $partnerName,
        public string $sellerName,
        public string $sellerEmail,
        public int $shops,
        public int $tills,
        public string $plan,
        /** Net, per till, as the partner sent it; null = the plan's. */
        public ?string $setupFeePerTill,
        public CarbonInterface $receivedAt,
        /** The partner's cancel reason (PARTNER_CANCELLED only). */
        public ?string $reason = null,
    ) {}
}
