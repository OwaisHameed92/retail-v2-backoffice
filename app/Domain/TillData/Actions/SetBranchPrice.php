<?php

namespace App\Domain\TillData\Actions;

use App\Domain\Shared\Country\LocalText;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\BranchPrice;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A shop's own price set on the portal (contract v1.4 §10.5, SHOP-OR-EVERY-SHOP.md "What the portal must do"):
 * always a **new** BranchPrice row, never an edit of an existing one (a till's row is never rewritten). Its
 * `validFromUtc` is later than every existing row of that shop and product (or unit) that has started by then, so
 * it wins on the till (the latest validFromUtc wins). The pull sends it to that shop only.
 *
 *     app(SetBranchPrice::class)->handle($branch, $productId, null, '1.39', CarbonImmutable::parse('2026-11-01'));
 *
 * Runs as the branch's company (BelongsToCompany fills company_id).
 */
final class SetBranchPrice
{
    public function handle(
        Branch $branch,
        string $productId,
        ?string $productUnitId,
        string $price,
        ?CarbonImmutable $validFrom = null,
        ?CarbonImmutable $validTo = null,
    ): BranchPrice {
        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($price)) !== 1) {
            throw ValidationException::withMessages(['price' => LocalText::currency('Enter the price in pounds, e.g. 1.39.')]);
        }

        $from = ($validFrom ?? CarbonImmutable::now())->utc()->startOfSecond();
        $latest = BranchPrice::query()
            ->forBranch($branch)
            ->forProduct($productId, $productUnitId)
            ->where('valid_from_utc', '<=', $from->format('Y-m-d H:i:s'))
            ->max('valid_from_utc');

        if ($latest !== null && CarbonImmutable::parse((string) $latest, 'UTC')->greaterThanOrEqualTo($from)) {
            $from = CarbonImmutable::parse((string) $latest, 'UTC')->addSecond();
        }

        $to = $validTo?->utc()->startOfSecond();

        if ($to !== null && ! $to->greaterThan($from)) {
            throw ValidationException::withMessages(['valid_to' => 'The price must end after it starts.']);
        }

        $row = new BranchPrice;
        $row->forceFill([
            'company_id' => $branch->company_id,
            'branch_id' => $branch->getKey(),
            'product_id' => $productId,
            'product_unit_id' => $productUnitId,
            'price' => Money::normalise(trim($price)),
            'valid_from_utc' => $from,
            'valid_to_utc' => $to,
        ])->save();

        return $row;
    }
}
