<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;

/**
 * Keeps the main-till invariant for one branch: when it has active registers, exactly one of them is the main
 * till; inactive registers are never main. Call inside the register actions' transaction and company scope.
 */
final class MainTill
{
    /**
     * Fix the branch's main flags. Keeps `$preferred` (or the current main) when it is active, otherwise
     * promotes the active register with the lowest code. Returns the main till, or null when none are active.
     */
    public static function normalise(Branch $branch, ?Register $preferred = null): ?Register
    {
        $registers = Register::query()->where('branch_id', $branch->getKey())->orderBy('code')->lockForUpdate()->get();
        $active = $registers->where('is_active', true);

        $main = null;
        if ($preferred !== null && $preferred->is_active) {
            $main = $active->firstWhere('id', $preferred->getKey());
        }
        $main ??= $active->firstWhere('is_main_till', true) ?? $active->first();

        foreach ($registers as $register) {
            $shouldBeMain = $main !== null && $register->is($main);

            if ($register->is_main_till !== $shouldBeMain) {
                $register->is_main_till = $shouldBeMain;
                $register->save();
            }
        }

        return $main;
    }
}
