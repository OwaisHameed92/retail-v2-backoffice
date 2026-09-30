<?php

namespace App\Domain\Compliance\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Enums\ProductRecallStatus;
use App\Domain\TillData\Models\ProductRecall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Close a product recall (stock dealt with; how much went back to the supplier) or reopen it (module 5.7). A hub-owned
 * save like SaveProductRecall: `row_version` + 1 and every till gets the new status in its next pull. `closedByUserId`
 * stays blank (a portal user is not a till user); the audit log says who.
 */
final class ChangeRecallStatus
{
    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $recallId, ProductRecallStatus $status, ?string $returnedQty = null, ?string $note = null): ProductRecall
    {
        return $this->tenancy->runAs($company, fn (): ProductRecall => DB::transaction(function () use ($recallId, $status, $returnedQty, $note): ProductRecall {
            $recall = ProductRecall::query()->lockForUpdate()->findOrFail($recallId);

            if ($recall->status === $status) {
                throw ValidationException::withMessages(['status' => $status === ProductRecallStatus::Closed ? 'This recall is already closed.' : 'This recall is already open.']);
            }

            $closing = $status === ProductRecallStatus::Closed;
            $recall->forceFill([
                'status' => $status,
                'closed_at' => $closing ? CarbonImmutable::now('UTC') : null,
                'closed_by_user_id' => '',
                'returned_qty' => $closing && $returnedQty !== null && $returnedQty !== '' ? Money::normalise($returnedQty, 4) : $recall->returned_qty,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : $recall->note,
                'row_version' => (int) $recall->row_version + 1,
            ])->save();

            $this->audit->handle($closing ? 'recall.closed' : 'recall.reopened', $recall, null, ['returned_qty' => $recall->returned_qty], ['reference' => $recall->reference]);

            return $recall;
        }));
    }
}
