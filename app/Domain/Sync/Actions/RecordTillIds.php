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
 * Refused (409 `licence.ids_conflict`, official since contract v1.4.1 answers (b), + a `tillIdsConflict` admin
 * alert): a till id already mapped to another business, or the till's branch id already mapped to another branch of
 * this business (its data would land in the wrong shop). Only `licence/activate` sends it (validate never re-checks
 * ids; redeem and migrate have their own codes). Call check() before the licence transaction (so the alert survives the error reply) and
 * handle() inside it.
 */
class RecordTillIds
{
    /**
     * `licence.ids_conflict` messages (contract v1.4.1 error-codes.json, ANSWERS-2026-09-29-b C): en-GB, for the shop
     * owner, at most 500 characters, never an id. The till shows them as sent (activate: nothing is saved).
     */
    public const ANOTHER_BUSINESS = "This till's shop details are already linked to another business on Switch & Save, so this licence key cannot be used on this PC. Nothing has been changed. Please call your dealer or Switch & Save support.";

    public const ANOTHER_BRANCH = 'This till holds the sales of another of your shops, so this licence key (issued for a different shop) cannot be used on this PC. Nothing has been changed. Enter the key issued for that shop, or call your dealer or Switch & Save support.';

    public function __construct(private readonly LicenceAlerts $alerts) {}

    /**
     * @throws ApiException licence.ids_conflict
     */
    public function check(Licence $licence, TillRequest $till): void
    {
        $problem = $this->plan($licence->company_id, $licence->branch_id, $licence->register_id, $till)['problem'];

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
        $plan = $this->plan($licence->company_id, $licence->branch_id, $licence->register_id, $till);

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
     * Module 2.8 (`cloud/migrate`): would the till's ids conflict with our business and shop? No alert, no write.
     *
     * @return string|null the conflicting kind (company, branch, register), null when none
     */
    public function conflict(string $companyId, string $branchId, TillRequest $till): ?string
    {
        return $this->plan($companyId, $branchId, null, $till)['problem']['kind'] ?? null;
    }

    /**
     * @return array{problem: array{kind: string, message: string}|null, writes: list<array{kind: IdKind, tillId: string, portalId: string|null, action: IdMapAction}>}
     */
    private function plan(string $companyId, string $branchId, ?string $registerId, TillRequest $till): array
    {
        $ids = $till->existingIds;
        $writes = [];

        if ($ids === null) {
            return ['problem' => null, 'writes' => []];
        }

        $wanted = [
            [IdKind::Company, $ids['companyId'], $companyId],
            [IdKind::Branch, $ids['branchId'], $branchId],
            [IdKind::Register, $ids['registerId'], $registerId],
        ];

        foreach ($wanted as [$kind, $tillId, $portalId]) {
            $mapped = IdMapping::withoutCompanyScope()->where('kind', $kind->value)->where('till_id', $tillId)->first();

            if ($mapped !== null && $mapped->company_id !== $companyId) {
                return ['problem' => ['kind' => $kind->value, 'message' => self::ANOTHER_BUSINESS], 'writes' => []];
            }

            if ($mapped !== null && $kind === IdKind::Branch && $mapped->portal_id !== $portalId) {
                return ['problem' => ['kind' => $kind->value, 'message' => self::ANOTHER_BRANCH], 'writes' => []];
            }

            if ($mapped !== null && ($kind === IdKind::Company || $mapped->portal_id === $portalId)) {
                continue;
            }

            $writes[] = ['kind' => $kind, 'tillId' => $tillId, 'portalId' => $portalId, 'action' => $this->action($kind, $companyId)];
        }

        return ['problem' => null, 'writes' => $writes];
    }

    private function action(IdKind $kind, string $companyId): IdMapAction
    {
        if ($kind !== IdKind::Company) {
            return IdMapAction::Adopted;
        }

        $known = IdMapping::withoutCompanyScope()->where('company_id', $companyId)->where('kind', IdKind::Company->value)->exists();

        return $known ? IdMapAction::Aliased : IdMapAction::Adopted;
    }
}
