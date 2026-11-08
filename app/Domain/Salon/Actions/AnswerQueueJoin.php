<?php

namespace App\Domain\Salon\Actions;

use App\Domain\Salon\Models\SalonQueueJoin;
use App\Domain\Salon\Online\SalonPayload;
use App\Domain\Salon\Queries\QueueJoinsForTill;
use App\Domain\Salon\Support\SalonApiErrors;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `POST sync/salon/queue-joins/{joinId}/answer` (W3a, salon pack README §4.4): the till's answer to a phone join,
 * `joined` (ticket, position, minutes to wait, estimated start) or `refused` (code and a message already in the
 * client's words). Body: `salon/schemas/queue-join-answer.schema.json`.
 *
 * - A join this shop does not have (unknown, another shop's, or dropped after 15 minutes) → 404 salon.join_not_found
 *   (`rejectedKey` = the id); the till moves on.
 * - The same answer again → 200 and nothing changes; a different answer to the same join (the till's answer was lost
 *   and it answered again) replaces it: the till is the queue of record.
 */
final class AnswerQueueJoin
{
    public const STATUSES = ['joined', 'refused'];

    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return array{result: string}
     *
     * @throws ApiException
     */
    public function handle(SyncCaller $caller, string $joinId, mixed $body): array
    {
        $answer = self::read($body);

        if ($answer['joinId'] !== $joinId) {
            throw SalonPayload::invalid('joinId', 'must be the join id in the address');
        }

        $this->tenancy->runAs($caller->company, function () use ($caller, $joinId, $answer): void {
            QueueJoinsForTill::dropStale($caller->branch->id);

            DB::transaction(function () use ($caller, $joinId, $answer): void {
                $join = SalonQueueJoin::query()->where('branch_id', $caller->branch->id)->whereKey($joinId)->lockForUpdate()->first();

                if ($join === null || $join->dropped_at !== null) {
                    throw SalonApiErrors::joinNotFound($joinId);
                }

                if ($join->answer === $answer) {
                    return;
                }

                $join->forceFill(['answer' => $answer, 'answer_status' => $answer['status'], 'answered_at' => CarbonImmutable::now('UTC')])->save();
            });
        });

        return ['result' => 'stored'];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private static function read(mixed $body): array
    {
        $p = SalonPayload::of($body);
        $status = $p->string('status', 20);

        if (! in_array($status, self::STATUSES, true)) {
            throw SalonPayload::invalid('status', 'must be joined or refused');
        }

        return [
            'joinId' => $p->string('joinId', 26, false),
            'status' => $status,
            'ticket' => $p->string('ticket', 20),
            'position' => $p->int('position', 0, 10000),
            'minutesToWait' => $p->nullableInt('minutesToWait', 0, 100000),
            'estimatedStartAt' => $p->nullableDateTime('estimatedStartAt')?->toIso8601ZuluString(),
            'code' => $p->string('code', 100),
            'message' => $p->string('message', 500),
        ];
    }
}
