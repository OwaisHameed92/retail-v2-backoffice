<?php

namespace App\Domain\Purchasing\Support;

/**
 * How many cases to suggest for one product at one shop, from the shop's stock (`BranchProduct`) and the product's
 * levels. Only when the stock is known and at or below the reorder point: fill up to the maximum (or reorder point
 * + the reorder quantity, or one case when neither is set), rounded up to whole cases. Otherwise 0 (no suggestion).
 */
final class ReorderSuggestion
{
    public static function cases(?string $onHand, ?string $reorderPoint, ?string $max, ?string $reorderQty, int $caseQty): int
    {
        if ($onHand === null || $reorderPoint === null || $caseQty < 1 || bccomp($onHand, $reorderPoint, 4) > 0) {
            return 0;
        }

        $target = $max !== null && bccomp($max, $reorderPoint, 4) > 0
            ? $max
            : bcadd($reorderPoint, $reorderQty !== null && bccomp($reorderQty, '0', 4) > 0 ? $reorderQty : (string) $caseQty, 4);
        $need = bcsub($target, $onHand, 4);

        if (bccomp($need, '0', 4) <= 0) {
            return 0;
        }

        $cases = bcdiv($need, (string) $caseQty, 0);

        return (int) $cases + (bccomp(bcmul($cases, (string) $caseQty, 4), $need, 4) < 0 ? 1 : 0);
    }
}
