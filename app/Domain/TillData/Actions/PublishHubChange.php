<?php

namespace App\Domain\TillData\Actions;

use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\HubVersions;
use App\Domain\TillData\Sync\RowHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Publishes a portal change of hub-owned rows to every branch's pull (module 2.5, contract §8, §19.2) when the code
 * that made it bypassed Eloquent (query builder, bulk import). Saves through a hub-owned model do this themselves
 * (HubOwnedRow). Marks the rows as the portal's content (`origin_branch_id` null, `hub_edited_at` now, `hub_hash` of
 * the stored row, so a till echoing it back is recognised) and stamps the next pull versions.
 *
 * Call it after the change is written, inside or after the same transaction. Returns the highest version given.
 */
final class PublishHubChange
{
    public function __construct(private readonly HubVersions $versions) {}

    /**
     * @param  list<string>  $ids
     */
    public function handle(string $companyId, string $entity, array $ids): ?int
    {
        $def = EntityRegistry::get($entity);

        if (! $def->isHubOwned() || $def->tenancy) {
            throw new InvalidArgumentException("{$entity} is not a hub-owned till entity: the portal never sends it.");
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table($def->table)->where('company_id', $companyId)->whereIn('id', $chunk)->get() as $row) {
                DB::table($def->table)->where('company_id', $companyId)->where('id', $row->id)->update([
                    'hub_version' => null,
                    'origin_branch_id' => null,
                    'hub_edited_at' => $now,
                    'hub_hash' => RowHash::of($def, (array) $row),
                ]);
            }
        }

        return $this->versions->stamp($companyId, $entity, $ids);
    }
}
