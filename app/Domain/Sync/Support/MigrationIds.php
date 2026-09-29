<?php

namespace App\Domain\Sync\Support;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Actions\RecordTillIds;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Models\IdMapping;
use App\Domain\Tenancy\Models\Branch;

/**
 * Ids on `cloud/migrate` (contract v1.4.1 §17.8 step 3): the till keeps its own company, branch and main-till ids;
 * we map them exactly as on `licence/activate` (RecordTillIds, module 2.1): the business's first till company id is
 * adopted, a later one aliased; the branch and register ids are adopted as the code's shop and the licence's till.
 * Ids that already belong to another business, or to another of this business's shops → 409
 * migrate.already_migrated. `idMapping` in the reply says what we did (`portalId` = our id; the till never re-keys).
 */
final class MigrationIds
{
    public function __construct(private readonly RecordTillIds $ids) {}

    /**
     * Before anything is written.
     *
     * @throws ApiException migrate.already_migrated
     */
    public function check(Branch $branch, TillRequest $till): void
    {
        $kind = $this->ids->conflict($branch->company_id, $branch->id, $till);

        if ($kind !== null) {
            throw self::conflict($kind);
        }
    }

    /**
     * Inside the migration's transaction, once the till's licence is known.
     *
     * @return array{company: array{localId: string, portalId: string, action: string}, branch: array{localId: string, portalId: string, action: string}}
     *
     * @throws ApiException migrate.already_migrated
     */
    public function record(Licence $licence, TillRequest $till): array
    {
        try {
            $this->ids->handle($licence, $till);
        } catch (ApiException $e) {
            throw self::conflict((string) ($e->details['kind'] ?? 'company'));
        }

        return [
            'company' => self::entry(IdKind::Company, (string) $till->existingIds['companyId'], $licence),
            'branch' => self::entry(IdKind::Branch, (string) $till->existingIds['branchId'], $licence),
        ];
    }

    /**
     * @return array{localId: string, portalId: string, action: string}
     */
    private static function entry(IdKind $kind, string $tillId, Licence $licence): array
    {
        $map = IdMapping::withoutCompanyScope()->where('company_id', $licence->company_id)->where('kind', $kind->value)->where('till_id', $tillId)->first();

        return [
            'localId' => $tillId,
            'portalId' => $map->portal_id ?? ($kind === IdKind::Company ? $licence->company_id : $licence->branch_id),
            'action' => $map?->action->value ?? 'adopted',
        ];
    }

    private static function conflict(string $kind): ApiException
    {
        return MigrationErrors::alreadyMigrated($kind === 'branch'
            ? 'This till holds the sales of another of your shops, so it cannot be connected with this shop\'s sync key. Use that shop\'s sync key.'
            : 'This shop\'s data is already linked to another account on Switch & Save.');
    }
}
