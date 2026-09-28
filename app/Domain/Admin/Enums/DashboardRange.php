<?php

namespace App\Domain\Admin\Enums;

/**
 * Range of the admin dashboard's revenue chart (`?range=`): 12 weekly bars, or 6 / 12 monthly ones.
 */
enum DashboardRange: string
{
    case TwelveWeeks = '12w';
    case SixMonths = '6m';
    case OneYear = '1y';

    public function label(): string
    {
        return match ($this) {
            self::TwelveWeeks => 'Last 12 weeks',
            self::SixMonths => 'Last 6 months',
            self::OneYear => 'Last 12 months',
        };
    }

    /** "vs previous 12 weeks" */
    public function previousLabel(): string
    {
        return match ($this) {
            self::TwelveWeeks => 'vs previous 12 weeks',
            self::SixMonths => 'vs previous 6 months',
            self::OneYear => 'vs previous 12 months',
        };
    }
}
