<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Licensing\Api\Support\LicenceReply;
use App\Domain\Licensing\Api\Support\LocalKeyRegister;
use App\Domain\Licensing\Api\Support\LocalToken;
use App\Domain\Licensing\Api\Support\RedeemErrors;
use App\Domain\Licensing\Api\Support\TillStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Signing\Sspos\LicenceClaims;
use App\Domain\Licensing\Signing\Sspos\VerifiedSsposToken;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Sync\Data\MigrationInput;
use App\Domain\Sync\Enums\CloudUploadStatus;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Models\CloudUpload;
use App\Domain\Sync\Support\MigrationCredential;
use App\Domain\Sync\Support\MigrationErrors;
use App\Domain\Sync\Support\MigrationIds;
use App\Domain\Sync\Support\MigrationLicence;
use App\Domain\Sync\Support\SyncKeyDelivery;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/v1/cloud/migrate` (module 2.8, contract v1.4.1 §17.8, SIMPLE-SETUP.md route A): a shop that never sent
 * anything links itself with its sync key (or an activated portal licence key) and gets an upload for its history.
 *
 * 1. The code (MigrationCredential): unknown 404, expired 410, used 409, shop not active 403.
 * 2. A local key in use is verified (§17.2; 422 licence.*) and entered in the local key register; a key the portal
 *    knows belongs to another business → 422 licence.wrong_shop.
 * 3. Another PC already moving (or moved) this shop → 409 device.branch_already_linked. Idempotent per
 *    (code's branch, installId): a retry gets the same upload, with `resumeFromSeq` = what we hold.
 * 4. Ids (MigrationIds): the till keeps its own; ours are mapped (adopted / aliased), or 409 migrate.already_migrated
 *    when they belong to another business or another shop.
 * 5. Licence and token (MigrationLicence, days carry over); `apiKey` = the sync key itself (a licence-key code gets
 *    the shop's sync key issued); `hubUrl` always (https).
 */
class MigrateToCloud
{
    public function __construct(
        private readonly MigrationCredential $credential,
        private readonly MigrationIds $ids,
        private readonly MigrationLicence $licences,
        private readonly LocalToken $tokens,
        private readonly LocalKeyRegister $register,
        private readonly IssueSyncKey $issueKey,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function handle(MigrationInput $in): array
    {
        $now = CarbonImmutable::now()->startOfSecond();
        ['branch' => $branch, 'syncKey' => $syncKey, 'licence' => $licence] = $this->credential->resolve($in->activationCode, $in->till);
        $local = $this->localToken($in, $branch);
        $this->ids->check($branch, $in->till);

        $reply = DB::transaction(function () use ($in, $branch, $syncKey, $licence, $local, $now) {
            $uploads = CloudUpload::withoutCompanyScope()->where('branch_id', $branch->id)->lockForUpdate()->get();
            $upload = $uploads->firstWhere('install_id', $in->till->installId);
            $other = $uploads->first(fn (CloudUpload $u) => $u->install_id !== $in->till->installId);

            if ($upload === null && $other !== null) {
                throw MigrationErrors::branchAlreadyLinked($branch->name, $other, $other->till_register_id);
            }

            $granted = $this->licences->forInstall($branch, $in->till, $licence, $local, $now);
            $mapping = $this->ids->record($granted['licence'], $in->till);
            $upload ??= $this->open($in, $branch, $granted['licence']->id, $syncKey?->id, $local, $granted['carriedOverDays'], $mapping);
            $apiKey = $syncKey !== null ? $in->activationCode : $this->issueKey->handle($branch, SyncKeySource::Till, installId: $in->till->installId);

            return $this->reply($in, $upload, $granted, $mapping, $apiKey, $now);
        });

        if ($local !== null) {
            $this->recordLocalKey($local, $in, $branch);
        }

        return $reply;
    }

    /**
     * @throws ApiException 422 licence.*
     */
    private function localToken(MigrationInput $in, Branch $branch): ?VerifiedSsposToken
    {
        if ($in->localLicenceToken === null) {
            return null;
        }

        $token = $this->tokens->verify($in->localLicenceToken);

        if ($token->source() !== 'local') {
            return null; // a portal licence: nothing carries over, the shop keeps ours
        }

        [$company] = LocalKeyRegister::resolve(LocalToken::text($token->get('companyId')), LocalToken::text($token->get('branchId')));

        if ($company !== null && $company !== $branch->company_id) {
            throw RedeemErrors::wrongShop();
        }

        return $token;
    }

    private function recordLocalKey(VerifiedSsposToken $local, MigrationInput $in, Branch $branch): void
    {
        try {
            $this->register->record($local, $in->till, $branch->company_id, $branch->id, 'migrate');
        } catch (ApiException) {
            // Already bound to another PC: the refusal is kept for support; the move itself goes on.
        }
    }

    /**
     * @param  array{company: array{localId: string, portalId: string, action: string}, branch: array{localId: string, portalId: string, action: string}}  $mapping
     */
    private function open(MigrationInput $in, Branch $branch, string $licenceId, ?string $syncKeyId, ?VerifiedSsposToken $local, int $carried, array $mapping): CloudUpload
    {
        $upload = CloudUpload::withoutCompanyScope()->create([
            'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'sync_key_id' => $syncKeyId, 'licence_id' => $licenceId,
            'install_id' => $in->till->installId, 'install_code' => $in->till->installCode, 'device_name' => mb_substr((string) $in->till->deviceName, 0, 100),
            'app_version' => $in->till->appVersion, 'till_company_id' => $in->tillCompanyId(), 'till_branch_id' => $in->tillBranchId(),
            'till_register_id' => $in->tillRegisterId(), 'status' => CloudUploadStatus::Open, 'expected_rows' => $in->totalRows,
            'expected_row_counts' => $in->rowCounts, 'snapshot_change_log_seq' => $in->snapshotChangeLogSeq,
            'first_sale_at' => $in->firstSaleAt, 'last_sale_at' => $in->lastSaleAt, 'local_licence_id' => $local?->licenceId(),
            'carried_over_days' => $carried, 'id_mapping' => $mapping,
        ]);

        $this->audit->handle('cloud.migration_started', $upload, null, [
            'branch_id' => $branch->id, 'expected_rows' => $in->totalRows, 'device_name' => $upload->device_name,
            'carried_over_days' => $carried, 'id_mapping' => array_map(fn (array $m) => $m['action'], $mapping),
        ], ['source' => 'tillApi'], companyId: $branch->company_id);

        return $upload;
    }

    /**
     * @param  array{licence: Licence, claims: LicenceClaims, status: string, token: string, carriedOverDays: int}  $granted
     * @param  array{company: array{localId: string, portalId: string, action: string}, branch: array{localId: string, portalId: string, action: string}}  $mapping
     * @return array<string, mixed>
     */
    private function reply(MigrationInput $in, CloudUpload $upload, array $granted, array $mapping, string $apiKey, CarbonImmutable $now): array
    {
        return [
            'companyId' => $in->tillCompanyId(),
            'branchId' => $in->tillBranchId(),
            'idMapping' => $mapping,
            'registers' => array_map(fn (array $r) => ['registerId' => $r['registerId'], 'seat' => 'allowed'], $in->registers),
            'hubUrl' => SyncKeyDelivery::hubUrl() ?? (string) preg_replace('#^http://#', 'https://', rtrim((string) config('app.url'), '/')),
            'apiKey' => $apiKey,
            'licenceToken' => $granted['token'],
            'licence' => LicenceReply::summary($granted['claims']),
            'carriedOverDays' => $upload->carried_over_days,
            'upload' => [
                'uploadId' => $upload->id,
                'mode' => 'initial',
                'maxRowsPerBatch' => max(100, min(5000, (int) config('sync.push.max_rows', 5000))),
                'resumeFromSeq' => $upload->isOpen() ? $upload->acknowledged_seq : max($upload->acknowledged_seq, $upload->received_rows),
            ],
            'settingsBootstrap' => null,
            'portalTimeUtc' => ApiDate::format($now),
            'nextCheckAfterSeconds' => TillStatus::nextCheckAfterSeconds($granted['status']),
            'messages' => $upload->isOpen() ? [[
                'id' => 'upload-'.$upload->id, 'level' => 'info', 'title' => 'Uploading your history',
                'text' => 'Your sales history is being copied to the portal in the background. Keep trading as normal.',
                'showFromUtc' => null, 'showUntilUtc' => null, 'dismissible' => true, 'link' => null,
            ]] : [],
        ];
    }
}
