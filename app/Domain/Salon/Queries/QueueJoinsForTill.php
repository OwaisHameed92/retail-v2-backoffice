<?php

namespace App\Domain\Salon\Queries;

use App\Domain\Salon\Models\SalonQueueJoin;
use App\Domain\Salon\Support\SalonOnlineSwitch;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * `GET sync/salon/queue-joins?max=20` (W3a, salon pack README §4.3): the shop's phone joins not yet answered, oldest
 * first, at most `max` (1–100, default 20), with `hasMore`. A join nobody answered within
 * `salon_online.join_ttl_minutes` (15) is dropped first, never listed again; its client is told to ask at the desk.
 * Only the calling shop's joins: another shop's never appear. Reply: `salon/schemas/queue-joins-reply.schema.json`.
 */
final class QueueJoinsForTill
{
    public const DEFAULT_MAX = 20;

    public const MAX = 100;

    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return array{joins: list<array<string, mixed>>, hasMore: bool}
     */
    public function handle(SyncCaller $caller, mixed $max): array
    {
        $limit = is_string($max) && ctype_digit($max) ? max(1, min(self::MAX, (int) $max)) : self::DEFAULT_MAX;

        return $this->tenancy->runAs($caller->company, function () use ($caller, $limit): array {
            self::dropStale($caller->branch->id);

            $joins = SalonQueueJoin::query()
                ->where('branch_id', $caller->branch->id)
                ->whereNull('answered_at')->whereNull('dropped_at')
                ->orderBy('joined_at')->orderBy('id')
                ->limit($limit + 1)
                ->get();

            return [
                'joins' => $joins->take($limit)->map(fn (SalonQueueJoin $join) => [
                    'joinId' => $join->id,
                    'firstName' => $join->first_name,
                    'phone' => $join->phone,
                    'serviceIds' => $join->service_ids,
                    'stylistUserId' => $join->stylist_user_id,
                    'note' => $join->note,
                    'joinedAt' => $join->joined_at->toIso8601ZuluString(),
                ])->values()->all(),
                'hasMore' => $joins->count() > $limit,
            ];
        });
    }

    /** Drops the shop's joins nobody answered in time. Runs inside the shop's company. */
    public static function dropStale(string $branchId): void
    {
        $now = CarbonImmutable::now('UTC');

        SalonQueueJoin::query()
            ->where('branch_id', $branchId)
            ->whereNull('answered_at')->whereNull('dropped_at')
            ->where('joined_at', '<=', $now->subMinutes(SalonOnlineSwitch::joinTtlMinutes())->format('Y-m-d H:i:s'))
            ->update(['dropped_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]);
    }
}
