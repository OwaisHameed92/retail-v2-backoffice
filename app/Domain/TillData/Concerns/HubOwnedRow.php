<?php

namespace App\Domain\TillData\Concerns;

use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Hub-owned till row (products, customers, VAT rates…): the portal may create and edit it, and every till applies
 * the portal's version. A portal save stamps `hub_edited_at` (the sync applier uses it to spot a till edit that
 * clashes with a newer portal edit) and `updated_at`; new rows get an upper-case ULID like till-made rows.
 * Module 2.5 adds the pull version counter (`hub_version`).
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
            $now = CarbonImmutable::now('UTC');
            $model->setAttribute('hub_edited_at', $now);

            if (! $model->isDirty('updated_at')) {
                $model->setAttribute('updated_at', $now);
            }
        });
    }

    public function isHubOwned(): bool
    {
        return true;
    }
}
