<?php

namespace App\Domain\TillData\Concerns;

use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\HubVersions;
use Illuminate\Database\Eloquent\Model;

/**
 * Company and Branch (module 1.2's tables) are the till's rows whose details the portal edits (contract v1.4.1 §6.1,
 * §18.3). A portal save that changes one of the till's members (the schema fields of the Company / Branch entity,
 * e.g. name, address, VAT number, a branch's code or active flag) queues the row for the pull: `hub_version` is
 * cleared and, once the transaction commits, stamped (HubVersions), so every till of the company (Company) or the
 * branch's till (Branch) receives it. The till's own pushes write with the query builder and are never sent back.
 * `nextPoNo` is the till's counter and never triggers a send.
 *
 * @mixin Model
 */
trait SentToTills
{
    public static function bootSentToTills(): void
    {
        static::updated(function (Model $model): void {
            $entity = class_basename($model);
            $columns = array_values(array_diff(
                array_map(fn ($field) => $field->column, EntityRegistry::get($entity)->fields),
                ['next_po_no'],
            ));

            if (! $model->wasChanged($columns)) {
                return;
            }

            $id = (string) $model->getKey();
            $companyId = (string) ($entity === 'Company' ? $id : $model->getAttribute('company_id'));
            $model->newQueryWithoutScopes()->whereKey($id)->update(['hub_version' => null]);
            $model->getConnection()->afterCommit(fn () => app(HubVersions::class)->stamp($companyId, $entity, [$id]));
        });
    }
}
