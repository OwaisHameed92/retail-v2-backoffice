<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Support\SyncStatusRecorder;
use Illuminate\Support\Facades\Log;

/**
 * `GET /api/v1/sync/hello` (module 2.2, contract v1.4.1 §4.1, schemas/hello-reply.schema.json): proves the address,
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

        $companyId = $caller->ids->toTill(IdKind::Company, $caller->company->id, $caller->branch->id);
        $branchId = $caller->ids->toTill(IdKind::Branch, $caller->branch->id, $caller->branch->id);

        // Ids are not secret: when they differ from the till's own, the till stops syncing ("key for another install").
        if ($companyId !== $caller->tillCompanyId || $branchId !== $caller->tillBranchId) {
            Log::warning('Sync hello: the ids we answer differ from the till\'s own.', [
                'tillCompanyId' => $caller->tillCompanyId, 'tillBranchId' => $caller->tillBranchId,
                'replyCompanyId' => $companyId, 'replyBranchId' => $branchId, 'tillRegisterId' => $tillRegisterId,
            ]);
        }

        return [
            'contractVersion' => 1,
            'companyId' => $companyId,
            'branchId' => $branchId,
            'branchName' => (string) $caller->branch->name,
            'serverTimeUtc' => ApiDate::now(),
            'maxBatchRows' => max(1, min(5000, (int) config('sync.push.max_rows', 5000))),
        ];
    }
}
