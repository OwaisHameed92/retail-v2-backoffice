<?php

namespace App\Domain\TillData\Concerns;

use App\Domain\Shared\Support\Ulid;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\RowHash;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Hub-owned till row (products, customers, VAT rates…): the portal may create and edit it, and every till applies
 * the portal's version. A portal save stamps `hub_edited_at` (the sync applier uses it to spot a till edit that
 * clashes with a newer portal edit) and `updated_at`; new rows get an upper-case ULID like till-made rows.
 *
 * Pull bookkeeping (docs/till-data.md, "Never echoed"): a save clears `hub_version` (module 2.5 stamps it with the
 * next pull version and sends the row to every branch), clears `origin_branch_id` (the portal made this content)
 * and sets `hub_hash`, so a till pushing the same content back is recognised as an echo.
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
        });
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
