<?php

namespace App\Domain\Sync\Queries;

use App\Domain\Licensing\Models\LocalLicenceKey;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Sync\Enums\CloudUploadStatus;
use App\Domain\Sync\Models\CloudUpload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Module 2.8 admin lists (contract v1.4.1 §17.10 "Migration" and "Local key register"): every shop's move to the
 * cloud with its upload progress, and every local (dealer) key a till reported, across businesses or for one.
 */
final class CloudLinkList
{
    /**
     * @return array{data: list<mixed>, meta: array<string, mixed>}
     */
    public static function uploads(Request $request, ?string $companyId = null): array
    {
        $table = TableQuery::from($request)
            ->searchable(['companies.name', 'branches.name', 'cloud_uploads.device_name', 'cloud_uploads.install_code'])
            ->sortable(['started_at', 'last_batch_at', 'completed_at', 'company_name'])
            ->defaultSort('started_at', 'desc');

        $query = CloudUpload::withoutCompanyScope()
            ->join('companies', 'companies.id', '=', 'cloud_uploads.company_id')
            ->leftJoin('branches', 'branches.id', '=', 'cloud_uploads.branch_id')
            ->select('cloud_uploads.*')
            ->addSelect(['companies.name as company_name', 'branches.name as branch_name', 'branches.code as branch_code', 'cloud_uploads.created_at as started_at'])
            ->when($companyId !== null, fn (Builder $q) => $q->where('cloud_uploads.company_id', $companyId));

        return $table->paginate($query, fn (CloudUpload $u) => CloudLinkPresenter::upload(
            $u, (string) $u->getAttribute('company_name'), $u->getAttribute('branch_name'), $u->getAttribute('branch_code'),
        ));
    }

    /**
     * @return array{data: list<mixed>, meta: array<string, mixed>}
     */
    public static function localKeys(Request $request, ?string $companyId = null, bool $refusedOnly = false): array
    {
        $now = CarbonImmutable::now();
        $table = TableQuery::from($request)
            ->searchable(['local_licence_keys.install_code', 'local_licence_keys.licence_id', 'local_licence_keys.business_name',
                'local_licence_keys.branch_name', 'local_licence_keys.device_name', 'local_licence_keys.token_sha256', 'companies.name'])
            ->sortable(['last_reported_at', 'first_seen_at', 'expires_at', 'refused_count'])
            ->defaultSort('last_reported_at', 'desc');

        $query = LocalLicenceKey::query()
            ->leftJoin('companies', 'companies.id', '=', 'local_licence_keys.company_id')
            ->select('local_licence_keys.*')
            ->addSelect(['companies.name as company_name'])
            ->when($companyId !== null, fn (Builder $q) => $q->where('local_licence_keys.company_id', $companyId))
            ->when($refusedOnly, fn (Builder $q) => $q->where('local_licence_keys.refused_count', '>', 0));

        $page = $table->paginate($query, fn (LocalLicenceKey $k) => $k);
        $shops = array_values(array_unique(array_filter(array_map(fn (LocalLicenceKey $k) => $k->claimed_branch_id, $page['data']))));
        $installs = $shops === [] ? [] : DB::table('local_licence_keys')->whereIn('claimed_branch_id', $shops)
            ->groupBy('claimed_branch_id')->selectRaw('claimed_branch_id, count(distinct install_code) as n')->pluck('n', 'claimed_branch_id')->all();

        $page['data'] = array_map(fn (LocalLicenceKey $k) => CloudLinkPresenter::localKey(
            $k, $k->getAttribute('company_name'), $k->claimed_branch_id === null ? null : (int) ($installs[$k->claimed_branch_id] ?? 1), $now,
        ), $page['data']);

        return $page;
    }

    /**
     * @return array{uploading: int, complete: int, localKeys: int, refused: int}
     */
    public static function summary(?string $companyId = null): array
    {
        $uploads = CloudUpload::withoutCompanyScope()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $keys = LocalLicenceKey::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId));

        return [
            'uploading' => (int) ($uploads[CloudUploadStatus::Open->value] ?? 0),
            'complete' => (int) ($uploads[CloudUploadStatus::Complete->value] ?? 0),
            'localKeys' => (clone $keys)->count(),
            'refused' => (clone $keys)->where('refused_count', '>', 0)->count(),
        ];
    }
}
