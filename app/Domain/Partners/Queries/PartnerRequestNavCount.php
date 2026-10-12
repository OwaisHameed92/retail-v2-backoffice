<?php

namespace App\Domain\Partners\Queries;

use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Models\PartnerTenantRequest;

/** Partner requests waiting for review (the "Partner requests" nav pill). */
final class PartnerRequestNavCount
{
    public static function get(): int
    {
        return PartnerTenantRequest::query()->where('status', PartnerRequestStatus::PendingReview->value)->count();
    }
}
