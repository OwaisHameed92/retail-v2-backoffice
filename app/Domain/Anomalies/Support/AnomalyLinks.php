<?php

namespace App\Domain\Anomalies\Support;

/**
 * Drill-down links of a finding (module 6.6), as portal paths with the shop, days and staff member already filtered.
 * The pages check their own abilities; a link the reader cannot open is hidden by the anomaly page.
 */
final class AnomalyLinks
{
    /**
     * @param  array<string, string|null>  $query
     * @return array{label: string, href: string}
     */
    public static function link(string $label, string $path, array $query = []): array
    {
        $query = array_filter($query, fn (?string $v) => $v !== null && $v !== '');

        return ['label' => $label, 'href' => $path.($query === [] ? '' : '?'.http_build_query($query))];
    }

    /**
     * @return array{label: string, href: string}
     */
    public static function sales(string $label, string $branchId, string $from, string $to, ?string $staff = null, ?string $status = null, ?string $till = null): array
    {
        return self::link($label, '/app/sales', ['from' => $from, 'to' => $to, 'shop' => $branchId, 'staff' => $staff, 'status' => $status, 'till' => $till]);
    }

    /**
     * @return array{label: string, href: string}
     */
    public static function exceptions(string $label, string $branchId, string $from, string $to, ?string $staff = null, ?string $type = null): array
    {
        return self::link($label, '/app/compliance/exceptions', ['from' => $from, 'to' => $to, 'shop' => $branchId, 'staff' => $staff, 'type' => $type]);
    }

    /**
     * @return array{label: string, href: string}
     */
    public static function shift(string $label, string $shiftId): array
    {
        return self::link($label, '/app/cash/shifts/'.rawurlencode($shiftId));
    }

    /**
     * @return array{label: string, href: string}
     */
    public static function staff(string $staffId): array
    {
        return self::link('Staff member', '/app/staff/'.rawurlencode($staffId).'/edit');
    }

    /**
     * The portal ability a link's page needs (null = none beyond the anomaly page).
     */
    public static function abilityFor(string $href): ?string
    {
        return match (true) {
            str_starts_with($href, '/app/sales') => 'sales.view',
            str_starts_with($href, '/app/compliance') => 'compliance.view',
            str_starts_with($href, '/app/cash') => 'cash.view',
            str_starts_with($href, '/app/staff/time') => 'staff.view',
            str_starts_with($href, '/app/staff') => 'staff.manage',
            str_starts_with($href, '/app/stock') => 'stock.view',
            str_starts_with($href, '/app/calendar') => 'calendar.manage',
            str_starts_with($href, '/app/shops') => 'shops.view',
            str_starts_with($href, '/app/reports') => 'reports.view',
            default => null,
        };
    }
}
