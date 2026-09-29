<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Licensing\Api\Support\LicenceAlerts;
use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Enums\IdMapAction;
use App\Domain\Sync\Models\IdMapping;

/**
 * Records a till's own ids (`existingIds`) against ours on `licence/activate` (module 2.1, contract v1.4.1
 * §17.3 step 3, ANSWERS §1). The till never re-keys; we map and translate at the edge (IdTranslator).
 *
 * - Company: the first till company id of a customer is **adopted**; a different one later (a second branch's main
 *   till made its own) is **aliased** to the same company, remembering the branch that uses it.
 * - Branch: the till's branch id is adopted as the branch **the key was issued for** (keys stay bound to their
 *   branch; existingIds never move a key).
 * - Register: the till's register id is mapped to the register the key is bound to (re-pointed within the
 *   business when a PC takes another till's key).
 *
 * Refused (409 `licence.ids_conflict` + a `tillIdsConflict` admin alert): a till id already mapped to another
 * business, or the till's branch id already mapped to another branch of this business (its data would land in
 * the wrong shop). Call check() before the licence transaction (so the alert survives the error reply) and
 * handle() inside it.
 */
class RecordTillIds
{
    public function __construct(private readonly LicenceAlerts $alerts) {}

    /**
     * @throws ApiException licence.ids_conflict
     */
    public function check(Licence $licence, TillRequest $till): void
    {
        $problem = $this->plan($licence, $till)['problem'];

        if ($problem !== null) {
            $this->alerts->raise($licence, LicenceAlertType::TillIdsConflict, $till, ['attempted' => 'activate', 'conflict' => $problem['kind']]);

            throw new ApiException('licence.ids_conflict', $problem['message'], 409, null, null, ['kind' => $problem['kind']]);
        }
    }

    /**
     * @throws ApiException licence.ids_conflict (a mapping made meanwhile)
     */
    public function handle(Licence $licence, TillRequest $till): void
    {
        $plan = $this->plan($licence, $till);

        if ($plan['problem'] !== null) {
            throw new ApiException('licence.ids_conflict', $plan['problem']['message'], 409, null, null, ['kind' => $plan['problem']['kind']]);
        }

        foreach ($plan['writes'] as $write) {
            IdMapping::withoutCompanyScope()->updateOrCreate(
                ['kind' => $write['kind']->value, 'till_id' => $write['tillId']],
                ['portal_id' => $write['portalId'], 'company_id' => $licence->company_id, 'branch_id' => $licence->branch_id, 'action' => $write['action'], 'install_id' => $till->installId],
            );
        }
    }

    /**
     * @return array{problem: array{kind: string, message: string}|null, writes: list<array{kind: IdKind, tillId: string, portalId: string, action: IdMapAction}>}
     */
    private function plan(Licence $licence, TillRequest $till): array
    {
        $ids = $till->existingIds;
        $writes = [];

        if ($ids === null) {
            return ['problem' => null, 'writes' => []];
        }

        $wanted = [
            [IdKind::Company, $ids['companyId'], $licence->company_id],
            [IdKind::Branch, $ids['branchId'], $licence->branch_id],
            [IdKind::Register, $ids['registerId'], $licence->register_id],
        ];

        foreach ($wanted as [$kind, $tillId, $portalId]) {
            $mapped = IdMapping::withoutCompanyScope()->where('kind', $kind->value)->where('till_id', $tillId)->first();

            if ($mapped !== null && $mapped->company_id !== $licence->company_id) {
                return ['problem' => ['kind' => $kind->value, 'message' => 'This PC holds the data of another business, so this licence key cannot be used on it. Please contact Switch & Save support.'], 'writes' => []];
            }

            if ($mapped !== null && $kind === IdKind::Branch && $mapped->portal_id !== $portalId) {
                return ['problem' => ['kind' => $kind->value, 'message' => 'This PC holds the data of another branch of your business. Use a licence key issued for that branch, or contact Switch & Save support.'], 'writes' => []];
            }

            if ($mapped !== null && ($kind === IdKind::Company || $mapped->portal_id === $portalId)) {
                continue;
            }

            $writes[] = ['kind' => $kind, 'tillId' => $tillId, 'portalId' => $portalId, 'action' => $this->action($kind, $licence)];
        }

        return ['problem' => null, 'writes' => $writes];
    }

    private function action(IdKind $kind, Licence $licence): IdMapAction
    {
        if ($kind !== IdKind::Company) {
            return IdMapAction::Adopted;
        }

        $known = IdMapping::withoutCompanyScope()->where('company_id', $licence->company_id)->where('kind', IdKind::Company->value)->exists();

        return $known ? IdMapAction::Aliased : IdMapAction::Adopted;
    }
}
