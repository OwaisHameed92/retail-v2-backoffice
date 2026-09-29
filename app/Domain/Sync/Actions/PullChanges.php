<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Sync\Support\PullFeed;
use App\Domain\Sync\Support\PullPayload;
use App\Domain\Sync\Support\SyncApiErrors;
use App\Domain\Sync\Support\SyncStatusRecorder;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\HubVersions;
use Illuminate\Support\Facades\DB;

/**
 * `GET /api/v1/sync/pull?since={version}&max={n}` (module 2.5, contract v1.3.3 §4, §5, §8, §10, §11, §19.2,
 * schemas/pull-reply.schema.json): the portal's changes the calling branch has not applied yet.
 *
 * 1. Stamps the company's unstamped hub-owned rows (HubVersions::stampPending): rows accepted from a till since the
 *    last pull, and any portal change whose after-commit stamp did not run.
 * 2. Reads, in one transaction (a consistent snapshot), up to `max` (≤ 5,000) rows with `version > since` visible to
 *    the branch (PullFeed): hub-owned entities only, company-wide or addressed to this branch, never a row whose
 *    current content this branch pushed. Oldest first.
 * 3. Envelopes in the till's shape with the till's own ids (PullPayload). `highestVersion` = the page's last version
 *    (`since` when nothing is new, as samples/pull-reply.empty.json); `hasMore` when rows are still waiting.
 * 4. Records the pull in `sync_branch_status`.
 */
final class PullChanges
{
    public const MAX_ROWS = 5000;

    public function __construct(
        private readonly HubVersions $versions,
        private readonly PullFeed $feed,
        private readonly SyncStatusRecorder $status,
    ) {}

    /**
     * @return array{changes: list<array<string, mixed>>, highestVersion: int, hasMore: bool}
     *
     * @throws ApiException
     */
    public function handle(SyncCaller $caller, mixed $since, mixed $max, string $appVersion, string $tillRegisterId): array
    {
        try {
            $since = $this->since($since);
            $max = $this->max($max);
        } catch (ApiException $e) {
            $this->status->failed($caller, $e, $appVersion, $tillRegisterId);

            throw $e;
        }

        $companyId = $caller->company->id;
        $this->versions->stampPending($companyId);

        $reply = DB::transaction(function () use ($caller, $companyId, $since, $max): array {
            $page = $this->feed->page($companyId, $caller->branch->id, $since, $max + 1);
            $hasMore = count($page) > $max;
            $page = array_slice($page, 0, $max);
            $payloads = new PullPayload($caller->ids, $caller->branch->id);
            $rows = [];

            foreach ($this->byEntity($page) as $entity => $ids) {
                $rows[$entity] = $this->feed->rows(EntityRegistry::get($entity), $companyId, $ids);
            }

            $changes = [];

            foreach ($page as [$entity, $id, $version]) {
                $row = $rows[$entity][$id] ?? null;

                // Changed since the page was read (not possible inside the snapshot, kept for READ COMMITTED
                // servers): its newer version comes in a later pull.
                if ($row !== null && (int) $row['hub_version'] === $version) {
                    $changes[] = $payloads->envelope(EntityRegistry::get($entity), $row, $version);
                }
            }

            return [
                'changes' => $changes,
                'highestVersion' => $page === [] ? $since : $page[count($page) - 1][2],
                'hasMore' => $hasMore,
            ];
        });

        $this->status->pulled($caller, $since, $reply['highestVersion'], count($reply['changes']), $appVersion, $tillRegisterId);

        return $reply;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $page
     * @return array<string, list<string>>
     */
    private function byEntity(array $page): array
    {
        $ids = [];

        foreach ($page as [$entity, $id]) {
            $ids[$entity][] = $id;
        }

        return $ids;
    }

    /**
     * @throws ApiException
     */
    private function since(mixed $since): int
    {
        if (! is_string($since) || preg_match('/^\d{1,18}$/', $since) !== 1) {
            throw SyncApiErrors::invalid('since must be the highest pull version the till has applied (0 the first time).');
        }

        return (int) $since;
    }

    /**
     * @throws ApiException
     */
    private function max(mixed $max): int
    {
        if ($max === null || $max === '') {
            return self::MAX_ROWS;
        }

        if (! is_string($max) || preg_match('/^\d{1,9}$/', $max) !== 1 || (int) $max < 1) {
            throw SyncApiErrors::invalid('max must be a whole number from 1 to '.self::MAX_ROWS.'.');
        }

        return min((int) $max, self::MAX_ROWS);
    }
}
