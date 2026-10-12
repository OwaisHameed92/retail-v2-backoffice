<?php

namespace App\Domain\Partners\Enums;

/**
 * Where a partner's tenant request is (docs/partner-api.md). camelCase values, as the partner reads them.
 *
 * - pendingReview → approved (an admin approved it: the business exists) | rejected (with a reason) | cancelled (the
 *   partner cancelled the deal before review).
 * - approved → cancelled only when the business itself is cancelled on the portal. A partner's cancel after approval
 *   never touches the business: the request stays `approved` and is flagged for an admin.
 */
enum PartnerRequestStatus: string
{
    case PendingReview = 'pendingReview';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
