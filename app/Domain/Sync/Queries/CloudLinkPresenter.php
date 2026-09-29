<?php

namespace App\Domain\Sync\Queries;

use App\Domain\Licensing\Models\LocalLicenceKey;
use App\Domain\Sync\Models\CloudUpload;
use Carbon\CarbonImmutable;

/**
 * Module 2.8: a shop's move to the cloud and a reported local key, as the admin screens show them. Never a key or a
 * whole install id: the end of the install id and the start of the token hash are enough for support.
 */
final class CloudLinkPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function upload(CloudUpload $upload, ?string $companyName = null, ?string $branchName = null, ?string $branchCode = null): array
    {
        $expected = max(0, $upload->expected_rows);

        return [
            'id' => $upload->id,
            'company' => ['id' => $upload->company_id, 'name' => $companyName],
            'branch' => ['id' => $upload->branch_id, 'name' => $branchName, 'code' => $branchCode],
            'status' => $upload->status->value,
            'statusLabel' => $upload->status->label(),
            'deviceName' => $upload->device_name,
            'installCode' => $upload->install_code,
            'appVersion' => $upload->app_version,
            'expectedRows' => $expected,
            'receivedRows' => $upload->received_rows,
            'percent' => $expected === 0 ? ($upload->isOpen() ? 0 : 100) : min(100, (int) floor($upload->received_rows * 100 / $expected)),
            'acknowledgedSeq' => $upload->acknowledged_seq,
            'missing' => $upload->missing ?? [],
            'completeCalls' => $upload->complete_calls,
            'carriedOverDays' => $upload->carried_over_days,
            'localLicenceId' => $upload->local_licence_id,
            'licenceId' => $upload->licence_id,
            'companyIdAction' => $upload->id_mapping['company']['action'] ?? null,
            'branchIdAction' => $upload->id_mapping['branch']['action'] ?? null,
            'firstSaleAt' => self::iso($upload->first_sale_at),
            'lastSaleAt' => self::iso($upload->last_sale_at),
            'startedAt' => self::iso($upload->created_at),
            'lastBatchAt' => self::iso($upload->last_batch_at),
            'completedAt' => self::iso($upload->completed_at),
        ];
    }

    /**
     * @param  int|null  $installsForShop  local keys reported for the same shop (the token's branchId)
     * @return array<string, mixed>
     */
    public static function localKey(LocalLicenceKey $key, ?string $companyName, ?int $installsForShop, CarbonImmutable $now): array
    {
        return [
            'id' => $key->id,
            'licenceId' => $key->licence_id,
            'installCode' => $key->install_code,
            'installIdEnding' => $key->install_id === null ? null : substr($key->install_id, -6),
            'deviceName' => $key->device_name,
            'appVersion' => $key->app_version,
            'businessName' => $key->business_name,
            'branchName' => $key->branch_name,
            'company' => $key->company_id === null ? null : ['id' => $key->company_id, 'name' => $companyName],
            'kind' => $key->kind,
            'issuer' => $key->issuer,
            'kid' => $key->kid,
            'tokenHashStart' => substr($key->token_sha256, 0, 12),
            'features' => $key->features ?? [],
            'maxRegisters' => $key->max_registers,
            'installsForShop' => $installsForShop,
            'expiresAt' => self::iso($key->expires_at),
            'expired' => $key->expires_at !== null && $key->expires_at->lessThan($now),
            'reportedVia' => $key->reported_via,
            'firstSeenAt' => self::iso($key->first_seen_at),
            'lastReportedAt' => self::iso($key->last_reported_at),
            'reportCount' => $key->report_count,
            'refusedCount' => $key->refused_count,
            'lastRefusedAt' => self::iso($key->last_refused_at),
        ];
    }

    private static function iso(?CarbonImmutable $at): ?string
    {
        return $at?->utc()->toIso8601ZuluString();
    }
}
