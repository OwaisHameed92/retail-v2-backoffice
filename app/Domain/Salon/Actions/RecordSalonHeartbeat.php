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
 * `PUT sync/salon/heartbeat` (W3a, salon pack README §4.2, §6): records that the shop's main till is still there
 * (`last_heard_at` = our clock) and its flags. A heartbeat whose `at` is older than the one held (sent late) still
 * counts as "heard" but never puts older flags back. Body: `salon/schemas/heartbeat.schema.json`.
 */
final class RecordSalonHeartbeat
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return array{result: string}
     *
     * @throws ApiException
     */
    public function handle(SyncCaller $caller, mixed $body): array
    {
        $p = SalonPayload::of($body);
        $at = $p->dateTime('at');
        $flags = [
            'accepting' => $p->bool('accepting'),
            'queue_join_open' => $p->bool('queueJoinOpen'),
            'pending_requests' => $p->int('pendingRequests', 0, 1000000),
            'offline_after_min' => $p->int('offlineAfterMin', 1, 1440),
        ];
        $p->nullableDateTime('pageGeneratedAt');

        $this->tenancy->runAs($caller->company, function () use ($caller, $at, $flags): void {
            $shop = SalonOnlineShops::forBranch($caller->branch);

            DB::transaction(function () use ($shop, $at, $flags): void {
                $held = SalonOnlineShop::query()->whereKey($shop->id)->lockForUpdate()->firstOrFail();
                $newer = $held->heartbeat_at === null || ! $at->lessThan($held->heartbeat_at);

                $held->forceFill([
                    'last_heard_at' => CarbonImmutable::now('UTC'),
                    ...($newer ? ['heartbeat_at' => $at, ...$flags] : []),
                ])->save();
            });
        });

        return ['result' => 'ok'];
    }
}
