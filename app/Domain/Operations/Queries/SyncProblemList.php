<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Support\SecretText;
use App\Domain\Operations\Support\TenancyNames;
use App\Domain\TillData\Sync\Enums\ConflictKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sync problems across every business, grouped by business + shop + till + entity (read only, admin cross-tenant):
 *
 * - `refused`: the last push error a shop's till got (sync_branch_status: rejected rows or a refused push);
 * - `unknown`: rows of an entity this portal does not know yet, kept raw (till_unknown_rows);
 * - `conflict`: open sync conflicts waiting for the business to decide (sync_conflicts).
 *
 * @phpstan-type ProblemRow array{key: string, kind: string, business: array{id: string, name: string}, shop: string|null, till: string|null, entity: string|null, count: int, lastSeenAt: string, detail: string|null}
 */
final class SyncProblemList
{
    /** Refused pushes and unknown rows older than this are not listed (open conflicts are, whatever their age). */
    public const WINDOW_DAYS = 7;

    public const LIMIT = 50;

    /**
     * @return array{rows: list<ProblemRow>, total: int, last24h: array{refused: int, unknown: int, conflict: int}}
     */
    public static function compute(CarbonImmutable $now): array
    {
        $since = $now->subDays(self::WINDOW_DAYS)->utc()->format('Y-m-d H:i:s');
        $groups = [...self::refused($since), ...self::unknown($since), ...self::conflicts()];

        usort($groups, fn (array $a, array $b) => strcmp($b['lastSeenAt'], $a['lastSeenAt']));

        $dayAgo = $now->subDay()->utc()->format('Y-m-d H:i:s');
        $last24h = ['refused' => 0, 'unknown' => 0, 'conflict' => 0];

        foreach ($groups as $group) {
            if ($group['lastSeenAt'] >= $dayAgo) {
                $last24h[$group['kind']]++;
            }
        }

        return ['rows' => self::present(array_slice($groups, 0, self::LIMIT)), 'total' => count($groups), 'last24h' => $last24h];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function refused(string $since): array
    {
        return DB::table('sync_branch_status')->where('last_error_at', '>=', $since)
            ->get(['company_id', 'branch_id', 'last_register_id', 'last_error_at', 'last_error_code', 'last_error_message', 'last_rejected_key', 'rows_rejected_today'])
            ->map(fn (object $row) => [
                'kind' => 'refused',
                'companyId' => (string) $row->company_id,
                'branchId' => $row->branch_id,
                'registerId' => $row->last_register_id,
                'entity' => $row->last_rejected_key === null ? null : Str::before((string) $row->last_rejected_key, ':'),
                'count' => max(1, (int) $row->rows_rejected_today),
                'lastSeenAt' => (string) $row->last_error_at,
                'detail' => trim(($row->last_error_code ? $row->last_error_code.': ' : '').(string) $row->last_error_message),
            ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function unknown(string $since): array
    {
        return DB::table('till_unknown_rows')->where('received_at', '>=', $since)
            ->groupBy('company_id', 'branch_id', 'register_id', 'entity')
            ->selectRaw('company_id, branch_id, register_id, entity, count(*) as n, max(received_at) as last_seen')
            ->get()
            ->map(fn (object $row) => [
                'kind' => 'unknown',
                'companyId' => (string) $row->company_id,
                'branchId' => $row->branch_id,
                'registerId' => $row->register_id,
                'entity' => (string) $row->entity,
                'count' => (int) $row->n,
                'lastSeenAt' => (string) $row->last_seen,
                'detail' => 'Kept as sent until the portal knows this kind of record.',
            ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function conflicts(): array
    {
        return DB::table('sync_conflicts')->where('status', 'open')
            ->groupBy('company_id', 'branch_id', 'entity', 'kind')
            ->selectRaw('company_id, branch_id, entity, kind, count(*) as n, max(created_at) as last_seen')
            ->get()
            ->map(fn (object $row) => [
                'kind' => 'conflict',
                'companyId' => (string) $row->company_id,
                'branchId' => $row->branch_id,
                'registerId' => null,
                'entity' => (string) $row->entity,
                'count' => (int) $row->n,
                'lastSeenAt' => (string) $row->last_seen,
                'detail' => ConflictKind::tryFrom((string) $row->kind)?->label() ?? (string) $row->kind,
            ])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<ProblemRow>
     */
    private static function present(array $groups): array
    {
        $companies = TenancyNames::companies(array_column($groups, 'companyId'));
        $branches = TenancyNames::branches(array_column($groups, 'branchId'));
        $registers = TenancyNames::registers(array_column($groups, 'registerId'));

        return array_map(fn (array $g) => [
            'key' => substr(hash('sha256', implode("\0", [$g['kind'], $g['companyId'], $g['branchId'], $g['registerId'], $g['entity'], $g['detail']])), 0, 16),
            'kind' => (string) $g['kind'],
            'business' => ['id' => (string) $g['companyId'], 'name' => $companies[$g['companyId']] ?? 'Deleted business'],
            'shop' => $g['branchId'] === null ? null : ($branches[$g['branchId']] ?? 'Unknown shop'),
            'till' => $g['registerId'] === null ? null : ($registers[$g['registerId']] ?? 'Unknown till'),
            'entity' => $g['entity'] === null ? null : (string) $g['entity'],
            'count' => (int) $g['count'],
            'lastSeenAt' => CarbonImmutable::parse((string) $g['lastSeenAt'], 'UTC')->toIso8601ZuluString(),
            'detail' => $g['detail'] === null || $g['detail'] === '' ? null : mb_strimwidth(SecretText::clean((string) $g['detail']), 0, 300, '…'),
        ], $groups);
    }
}
