<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Support\Portal\ShopPin;
use App\Domain\Ai\Support\Portal\ToolWindow;
use App\Domain\Shared\Support\Money;
use App\Domain\StaffTime\Data\TimeFilters;
use App\Domain\StaffTime\Queries\Timesheets;
use App\Domain\Tenancy\Enums\Ability;

/**
 * Read: hours worked per person and shop for a period (module 5.6 Timesheets, the till's clock events and rota).
 * Pay rates are left out (personal data the question does not need); the wage estimate is a business total only.
 */
final class GetStaffHours extends PortalReadTool
{
    public function name(): string
    {
        return 'get_staff_hours';
    }

    public function description(): string
    {
        return 'Staff hours for a period (at most 92 days) from the till\'s clock-ins: per person and shop, shifts, '
            .'hours worked, breaks, paid hours, overtime (over 8 hours in a day), rota hours planned and missing '
            .'clock-outs; plus totals and the estimated wage bill.';
    }

    public function inputSchema(): array
    {
        return self::object(ToolWindow::properties());
    }

    public function rules(): array
    {
        return ToolWindow::rules();
    }

    public function requiredAbility(): Ability
    {
        return Ability::StaffView;
    }

    public function handle(array $input, AiContext $context): array
    {
        $shop = ShopPin::resolve($input['shop_id'] ?? null);
        $window = ToolWindow::filters($input, $shop);
        $last = $window->from->addDays(TimeFilters::MAX_DAYS - 1);
        $end = $window->to->greaterThan($last) ? $last : $window->to;
        [$from, $to] = [$window->from->toDateString(), $end->toDateString()];
        $filters = new TimeFilters(from: $from, to: $to, shop: $shop->id, group: 'period', shopLocked: $shop->pinned);
        $rows = Timesheets::rows($filters);
        $hours = fn (int $minutes) => Money::round(bcdiv((string) $minutes, '60', 6), 2);
        $wages = array_values(array_filter(array_column($rows, 'wage'), fn (mixed $w) => $w !== null));

        $this->links->add('Timesheets, '.ToolWindow::label($window->from, $end).' · '.$shop->name, '/app/staff/time/timesheets', [
            'from' => $from, 'to' => $to, 'group' => 'period', 'shop' => $shop->pinned ? null : ($shop->id ?? 'all'),
        ], $shop);

        return [
            ...$shop->toArray(),
            'period' => ['from' => $from, 'to' => $to],
            'totals' => [
                'people' => count(array_unique(array_column($rows, 'personId'))),
                'hoursWorked' => $hours(array_sum(array_column($rows, 'workedMinutes'))),
                'paidHours' => $hours(array_sum(array_column($rows, 'paidMinutes'))),
                'overtimeHours' => $hours(array_sum(array_column($rows, 'overtimeMinutes'))),
                'plannedHours' => $hours(array_sum(array_column($rows, 'plannedMinutes'))),
                'missingClockOuts' => array_sum(array_column($rows, 'missing')),
                'estimatedWages' => $wages === [] ? null : Money::sum($wages),
            ],
            'people' => array_map(fn (array $r) => [
                'person' => $r['person'],
                'shop' => $r['shop'],
                'shifts' => $r['shifts'],
                'hoursWorked' => $hours((int) $r['workedMinutes']),
                'breakHours' => $hours((int) $r['breakMinutes']),
                'paidHours' => $hours((int) $r['paidMinutes']),
                'overtimeHours' => $hours((int) $r['overtimeMinutes']),
                'plannedHours' => $hours((int) $r['plannedMinutes']),
                'missingClockOuts' => $r['missing'],
            ], array_slice($rows, 0, 60)),
        ];
    }
}
