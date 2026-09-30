<?php

namespace App\Domain\Shops\Queries;

use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Shops\Enums\ShopRequestKind;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;

/**
 * The business's "Ask for more tills / another shop" requests (module 4.7): open ones first, then the last done.
 * Read through the company scope; a one-shop user sees their shop's requests only.
 */
final class ShopRequests
{
    public const LIMIT = 10;

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(?string $branchId = null): array
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();
        $branchId ??= $restricted;

        $alerts = LicenceAlert::query()
            ->where('type', LicenceAlertType::TillsRequested->value)
            ->orderByRaw('case when resolved_at is null then 0 else 1 end')
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get()
            ->filter(fn (LicenceAlert $alert) => $branchId === null || ($alert->details['branchId'] ?? null) === $branchId)
            ->take(self::LIMIT)
            ->values();

        $names = Branch::query()->withTrashed()
            ->whereIn('id', $alerts->map(fn (LicenceAlert $alert) => $alert->details['branchId'] ?? null)->filter()->unique()->values()->all())
            ->get(['id', 'name', 'code'])->keyBy('id');

        return $alerts->map(function (LicenceAlert $alert) use ($names) {
            $details = $alert->details ?? [];
            $kind = ShopRequestKind::tryFrom((string) ($details['kind'] ?? '')) ?? ShopRequestKind::MoreTills;
            $branch = $names->get((string) ($details['branchId'] ?? ''));

            return [
                'id' => $alert->id,
                'kind' => $kind->value,
                'kindLabel' => $kind->label(),
                'tills' => (int) ($details['tills'] ?? 0),
                'shop' => $branch === null ? null : ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code],
                'newShopName' => $details['newShopName'] ?? null,
                'message' => $details['message'] ?? null,
                'requestedBy' => $details['requestedBy'] ?? null,
                'count' => $alert->count,
                'sentAt' => LicenceData::date($alert->first_seen_at),
                'lastAskedAt' => LicenceData::date($alert->last_seen_at),
                'done' => $alert->resolved_at !== null,
                'doneAt' => LicenceData::date($alert->resolved_at),
            ];
        })->all();
    }
}
