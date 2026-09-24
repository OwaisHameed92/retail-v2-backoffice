<?php

namespace App\Domain\Leads\Support;

use App\Domain\Leads\Data\TrialSetup;
use App\Domain\Leads\Data\TrialShop;
use App\Domain\Leads\Models\Lead;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Tenancy\Data\NewTenant;

/**
 * The approval dialog's starting point: one branch per shop the lead asked for, their tills spread across the
 * shops (every shop gets at least one; earlier shops take the remainder), suggested names and codes, and the
 * portal default plan. Staff can change all of it before confirming.
 */
final class TrialSuggestion
{
    public static function for(Lead $lead): TrialSetup
    {
        $shops = max(1, min($lead->shops_count, Lead::MAX_SHOPS));
        $tills = max($lead->tills_count, $shops);
        $each = intdiv($tills, $shops);
        $extra = $tills % $shops;

        $list = [];
        $taken = [];

        for ($i = 0; $i < $shops; $i++) {
            $name = self::name($lead, $i, $shops);
            $code = BranchCodeSuggester::suggest($name, $taken);
            $taken[] = $code;

            $list[] = new TrialShop(
                name: $name,
                code: $code,
                tills: min(NewTenant::MAX_TILLS, $each + ($i < $extra ? 1 : 0)),
            );
        }

        return new TrialSetup($list, DefaultPlan::portal()?->id);
    }

    private static function name(Lead $lead, int $index, int $shops): string
    {
        if ($index === 0) {
            return $lead->town ?? ($shops === 1 ? $lead->business_name : 'Shop 1');
        }

        return 'Shop '.($index + 1);
    }
}
