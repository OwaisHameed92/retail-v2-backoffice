<?php

namespace App\Domain\Sync\Data;

use App\Domain\Plans\Enums\Feature;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Sync\Support\SyncKeySecret;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The "Sync key" panel of each branch on the admin tenant page (module 2.1). Never the key: status, last 4, who
 * made it, when it was sent to the main till and last used.
 */
final class SyncKeyData
{
    /**
     * @param  Collection<int, Branch>  $branches
     * @return array<string, array<string, mixed>> branch id => panel
     */
    public static function forBranches(Company $company, Collection $branches): array
    {
        $now = CarbonImmutable::now();
        $keys = SyncKey::withoutCompanyScope()->where('company_id', $company->id)->get()->groupBy('branch_id');
        $planFeatures = $company->plan->features ?? collect();
        $panels = [];

        foreach ($branches as $branch) {
            /** @var Collection<int, SyncKey> $ofBranch */
            $ofBranch = $keys->get($branch->id, collect());
            $panels[$branch->id] = self::panel($branch, $ofBranch, $planFeatures, $now);
        }

        return $panels;
    }

    /**
     * @param  Collection<int, SyncKey>  $keys
     * @param  iterable<Feature>  $planFeatures
     * @return array<string, mixed>
     */
    private static function panel(Branch $branch, Collection $keys, iterable $planFeatures, CarbonImmutable $now): array
    {
        $current = $keys->first(fn (SyncKey $key) => $key->isCurrent());
        $features = Feature::normalise($branch->licence_features ?? $planFeatures);

        return [
            'status' => $current !== null ? 'active' : ($keys->isEmpty() ? 'none' : 'revoked'),
            'cloudSync' => in_array(Feature::CloudSync, $features, true),
            'maskedKey' => $current === null ? null : SyncKeySecret::mask($current->key_last4),
            'source' => $current?->source->value,
            'createdAt' => $current?->created_at?->toIso8601String(),
            'deliveredAt' => $current?->delivered_at?->toIso8601String(),
            'lastUsedAt' => $current?->last_used_at?->toIso8601String(),
            'rotationPending' => $current?->rotate_requested_at !== null,
            'revokedAt' => $current === null ? $keys->max('revoked_at')?->toIso8601String() : null,
            'oldKeysInGrace' => $keys->filter(fn (SyncKey $key) => ! $key->isCurrent() && $key->isUsable($now))->count(),
        ];
    }
}
