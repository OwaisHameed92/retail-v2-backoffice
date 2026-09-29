<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Support\SyncStatusRecorder;

/**
 * `GET /api/v1/sync/hello` (module 2.2, contract v1.3.3 §4.1, schemas/hello-reply.schema.json): proves the address,
 * the key and the key's branch. `companyId` / `branchId` are the key's company and branch **as the till knows them**
 * (id_map, IdTranslator::toTill; the till compares them with its own). `maxBatchRows` = config('sync.push.max_rows').
 */
final class SayHello
{
    public function __construct(private readonly SyncStatusRecorder $status) {}

    /**
     * @return array{contractVersion: int, companyId: string, branchId: string, branchName: string, serverTimeUtc: string, maxBatchRows: int}
     */
    public function handle(SyncCaller $caller, string $appVersion, string $tillRegisterId): array
    {
        $this->status->hello($caller, $appVersion, $tillRegisterId);

        return [
            'contractVersion' => 1,
            'companyId' => $caller->ids->toTill(IdKind::Company, $caller->company->id, $caller->branch->id),
            'branchId' => $caller->ids->toTill(IdKind::Branch, $caller->branch->id, $caller->branch->id),
            'branchName' => (string) $caller->branch->name,
            'serverTimeUtc' => ApiDate::now(),
            'maxBatchRows' => max(1, min(5000, (int) config('sync.push.max_rows', 5000))),
        ];
    }
}
