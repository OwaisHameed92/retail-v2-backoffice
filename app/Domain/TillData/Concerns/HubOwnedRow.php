<?php

namespace App\Domain\TillData\Concerns;

use App\Domain\Shared\Support\Ulid;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\BranchDepartures;
use App\Domain\TillData\Sync\HubVersions;
use App\Domain\TillData\Sync\RowHash;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Hub-owned till row (products, customers, VAT rates…): the portal may create and edit it, and every till applies
 * the portal's version. A portal save stamps `hub_edited_at` (the sync applier uses it to spot a till edit that
 * clashes with a newer portal edit) and `updated_at`; new rows get an upper-case ULID like till-made rows.
 *
 * Pull bookkeeping (docs/till-data.md, "Never echoed", "Pull"): a save or soft delete clears `origin_branch_id` (the
 * portal made this content), sets `hub_hash` (a till pushing the same content back is an echo) and, once the
 * transaction commits, stamps the next pull version (HubVersions), so every branch receives it. A new row also gets
 * `createdAt` = `updatedAt` and `rowVersion` 1, as the till writes them.
 *
 * @mixin Model
 */
trait HubOwnedRow
{
    public static function bootHubOwnedRow(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), Ulid::new());
            }

            if ($model->getAttribute('created_at') === null) {
                $model->setAttribute('created_at', $model->getAttribute('updated_at') ?? CarbonImmutable::now('UTC'));
            }

            if ($model->getAttribute('row_version') === null) {
                $model->setAttribute('row_version', 1);
            }
        });

        static::saved(function (Model $model): void {
            BranchDepartures::fromModel($model);   // moved to another shop: the old shop gets a `D` (ANSWERS-b A.3)
            self::publishHubVersion($model);
        });

        static::saving(function (Model $model): void {
            if ($model->exists && $model->isDirty('hub_version') && $model->getAttribute('hub_version') !== null) {
                return; // Module 2.5 stamping a pull version: not a content change.
            }

            $now = CarbonImmutable::now('UTC');
            $model->setAttribute('hub_edited_at', $now);

            if (! $model->isDirty('updated_at')) {
                $model->setAttribute('updated_at', $now);
            }

            $model->setAttribute('hub_version', null);
            $model->setAttribute('origin_branch_id', null);
            $model->setAttribute('hub_hash', self::hubHash($model));
        });

        // A soft delete writes only deleted_at, without `saving`: stamp it as a portal change too, so a late till
        // update cannot bring the row back (§19.3) and the pull sends the delete.
        static::registerModelEvent('trashed', function (Model $model): void {
            $model->newQueryWithoutScopes()->whereKey($model->getKey())->update([
                'hub_edited_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
                'hub_version' => null,
                'origin_branch_id' => null,
                'hub_hash' => self::hubHash($model),
            ]);

            self::publishHubVersion($model);
        });
    }

    /** Stamps the row's pull version after the surrounding transaction commits (at once when there is none). */
    private static function publishHubVersion(Model $model): void
    {
        $companyId = (string) $model->getAttribute('company_id');
        $id = (string) $model->getKey();
        $entity = (string) constant($model::class.'::TILL_ENTITY');

        $model->getConnection()->afterCommit(fn () => app(HubVersions::class)->stamp($companyId, $entity, [$id]));
    }

    private static function hubHash(Model $model): string
    {
        return RowHash::of(EntityRegistry::get((string) constant($model::class.'::TILL_ENTITY')), $model->getAttributes());
    }

    public function isHubOwned(): bool
    {
        return true;
    }
}
