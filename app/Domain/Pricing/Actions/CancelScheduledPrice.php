<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\TillData\Models\BranchPrice;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a shop price that has not started yet (module 4.3): its end is set to its start, so it is never live on
 * any till. The row is kept (history, and a till that already holds it gets the update in its pull), never deleted.
 */
final class CancelScheduledPrice
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(BranchPrice $row): BranchPrice
    {
        $from = $row->valid_from_utc;

        if (! $from->greaterThan(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages(['price' => 'This price has already started. End it instead.']);
        }

        if ($row->valid_to_utc !== null && $row->valid_to_utc->lessThanOrEqualTo($from)) {
            return $row; // Cancelled before.
        }

        $row->forceFill(['valid_to_utc' => $from, 'row_version' => (int) $row->row_version + 1])->save();

        $this->audit->handle('price.shop_cancelled', $row, ['valid_to_utc' => null], ['valid_to_utc' => $from->toIso8601ZuluString()], [
            'branch_id' => $row->branch_id, 'product_id' => $row->product_id, 'price' => $row->price,
        ]);

        return $row;
    }
}
