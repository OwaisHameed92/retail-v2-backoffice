<?php

namespace App\Domain\Licensing\Support;

use App\Domain\Licensing\Enums\AddOn;
use App\Domain\Tenancy\Models\Branch;

/**
 * A shop's paid add-ons (`branches.licence_add_ons`, AddOn): which it has, and the till names they add to the licence
 * token. Null or [] for every shop until one is ticked, so every token stays exactly as before.
 */
final class BranchAddOns
{
    /**
     * @return list<AddOn> in enum order, unknown values dropped
     */
    public static function of(?Branch $branch): array
    {
        $values = $branch === null ? [] : (array) ($branch->licence_add_ons ?? []);
        $addOns = [];

        foreach (AddOn::cases() as $addOn) {
            if (in_array($addOn->value, $values, true)) {
                $addOns[] = $addOn;
            }
        }

        return $addOns;
    }

    public static function has(?Branch $branch, AddOn $addOn): bool
    {
        return in_array($addOn, self::of($branch), true);
    }

    /**
     * The token's features with the shop's add-ons appended. A trial licence with no plan features means "every
     * feature on" to the till (common.schema.json `features`), so nothing is appended there (`$openTrial`): a list
     * would switch the other features off.
     *
     * @param  list<string>  $features  the plan features already in the token
     * @return list<string>
     */
    public static function appendTo(array $features, ?Branch $branch, bool $openTrial): array
    {
        $addOns = self::of($branch);

        if ($addOns === [] || $openTrial) {
            return $features;
        }

        foreach ($addOns as $addOn) {
            if (! in_array($addOn->value, $features, true)) {
                $features[] = $addOn->value;
            }
        }

        return $features;
    }
}
