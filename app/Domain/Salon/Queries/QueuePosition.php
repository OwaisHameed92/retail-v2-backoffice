<?php

namespace App\Domain\Salon\Queries;

use App\Domain\Salon\Availability\LondonClock;
use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Models\SalonQueueJoin;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * The "my position" page of a phone joiner (W3a, salon pack README §4.3–§4.5), read again every 20 seconds:
 *
 * - `sent`: the till has not picked the join up yet;
 * - `dropped`: nobody answered within 15 minutes → ask at the desk;
 * - `refused`: the till's message, as it wrote it (already in the client's words);
 * - `waiting` / `called` / `inService` / `done` / `left` / `noShow`: from the newest `PUT sync/salon/queue` entry for
 *   this join (else the till's answer): ticket, place, minutes to wait. `called` = "Please come to the desk now".
 *
 * Only this join's own ticket: never another client's name or phone, never the joiner's own phone either.
 */
final class QueuePosition
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return array<string, mixed>|null null when this shop has no such join
     */
    public function handle(SalonOnlineShop $shop, string $joinId): ?array
    {
        return $this->tenancy->runAs($shop->company()->firstOrFail(), function () use ($shop, $joinId): ?array {
            QueueJoinsForTill::dropStale($shop->branch_id);
            $join = SalonQueueJoin::query()->where('branch_id', $shop->branch_id)->find($joinId);

            if ($join === null) {
                return null;
            }

            $entry = null;

            foreach ((array) ($shop->queue['entries'] ?? []) as $row) {
                if (is_array($row) && ($row['joinId'] ?? null) === $join->id) {
                    $entry = $row;
                }
            }

            $answer = $join->answer ?? [];
            $state = match (true) {
                is_array($entry) => (string) $entry['status'],
                $join->answer_status === 'refused' => 'refused',
                $join->answer_status === 'joined' => 'waiting',
                $join->dropped_at !== null => 'dropped',
                default => 'sent',
            };
            $place = is_array($entry) ? $entry : $answer;
            $estimated = is_string($place['estimatedStartAt'] ?? null) ? CarbonImmutable::parse($place['estimatedStartAt']) : null;

            return [
                'state' => $state,
                'ticket' => (string) ($place['ticket'] ?? ''),
                'position' => isset($place['position']) ? (int) $place['position'] : null,
                'minutesToWait' => isset($place['minutesToWait']) ? (int) $place['minutesToWait'] : null,
                'estimatedStart' => $estimated === null ? null : LondonClock::label($estimated, 'H:i'),
                'message' => $state === 'refused' ? (string) ($answer['message'] ?? '') : '',
                'firstName' => $join->first_name,
                'joinedAt' => LondonClock::label($join->joined_at, 'H:i'),
            ];
        });
    }
}
