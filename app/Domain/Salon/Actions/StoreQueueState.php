<?php

namespace App\Domain\Salon\Actions;

use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Online\SalonOnlineShops;
use App\Domain\Salon\Online\SalonPayload;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `PUT sync/salon/queue` (W3a, salon pack README §4.5): today's places of the shop's phone joiners, which the "my
 * position" page reads. The newest `at` is kept: an older push (sent late) → `unchanged`, nothing written. No names or
 * phone numbers come in it and none are stored. Body: `salon/schemas/queue.schema.json`.
 */
final class StoreQueueState
{
    public const STATUSES = ['waiting', 'called', 'inService', 'done', 'left', 'noShow'];

    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return array{result: string}
     *
     * @throws ApiException
     */
    public function handle(SyncCaller $caller, mixed $body): array
    {
        $queue = self::read($body);
        $at = CarbonImmutable::parse($queue['at']);

        $result = $this->tenancy->runAs($caller->company, function () use ($caller, $queue, $at): string {
            $shop = SalonOnlineShops::forBranch($caller->branch);

            return DB::transaction(function () use ($shop, $queue, $at): string {
                $held = SalonOnlineShop::query()->whereKey($shop->id)->lockForUpdate()->firstOrFail();

                if ($held->queue_at !== null && ! $at->greaterThan($held->queue_at) && $held->queue === $queue) {
                    return 'unchanged';
                }

                if ($held->queue_at !== null && $at->lessThan($held->queue_at)) {
                    return 'unchanged';
                }

                $held->forceFill(['queue' => $queue, 'queue_at' => $at])->save();

                return 'stored';
            });
        });

        return ['result' => $result];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private static function read(mixed $body): array
    {
        $p = SalonPayload::of($body);

        return [
            'at' => $p->dateTime('at')->toIso8601ZuluString(),
            'tradingDate' => $p->date('tradingDate'),
            'isOpen' => $p->bool('isOpen'),
            'waiting' => $p->int('waiting', 0, 100000),
            'walkInMinutesToWait' => $p->nullableInt('walkInMinutesToWait', 0, 100000),
            'entries' => array_map(function (SalonPayload $e): array {
                $status = $e->string('status', 20);

                if (! in_array($status, self::STATUSES, true)) {
                    throw SalonPayload::invalid($e->at('status'), 'must be waiting, called, inService, done, left or noShow');
                }

                return [
                    'joinId' => $e->string('joinId', 26, false),
                    'ticket' => $e->string('ticket', 20),
                    'status' => $status,
                    'position' => $e->int('position', 0, 10000),
                    'minutesToWait' => $e->nullableInt('minutesToWait', 0, 100000),
                    'estimatedStartAt' => $e->nullableDateTime('estimatedStartAt')?->toIso8601ZuluString(),
                ];
            }, $p->objects('entries', 2000)),
        ];
    }
}
