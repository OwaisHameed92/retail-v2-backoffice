<?php

namespace App\Domain\Licensing\Enums;

/**
 * Paid add-ons sold per shop on top of the plan's features (wave W5, DECISIONS "BikeBook connector (W5)"). The value
 * is the till's feature name and goes into the licence token's open `features` list as it is
 * (licensing/schemas/common.schema.json `featureName`). Stored in `branches.licence_add_ons`; the plan `Feature`
 * enum (the till's 11 plan features) is not changed, so no plan, plan form or existing licence changes.
 *
 * Price: later (owner, WORKSHOP-BIKEBOOK.md §10 Q10); nothing is billed for an add-on today.
 */
enum AddOn: string
{
    case WorkshopIntegration = 'workshop_integration';

    public function label(): string
    {
        return match ($this) {
            self::WorkshopIntegration => 'Workshop integration (BikeBook)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::WorkshopIntegration => 'BikeBook sends repair jobs to the till as workshop orders and is told when they are paid.',
        };
    }
}
