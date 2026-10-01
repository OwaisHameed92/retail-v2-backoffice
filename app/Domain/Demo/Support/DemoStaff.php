<?php

namespace App\Domain\Demo\Support;

use App\Domain\Demo\Catalogue\DemoPeople;
use App\Domain\Reporting\Demo\DemoShop;

/**
 * Who works where in a demo business: the owner (every shop), and per shop a manager, the three cashiers the demo
 * sales are rung up by (DemoShop::cashiers, so their ids match), and a weekend part-timer.
 *
 * @phpstan-type Member array{id: string, name: string, role: string, kind: string, rate: int, shops: list<DemoShop>, age: int, active: bool}
 */
final class DemoStaff
{
    /** Role key => [name, level]. */
    public const ROLES = ['owner' => ['Owner', 100], 'manager' => ['Manager', 80], 'supervisor' => ['Supervisor', 50], 'cashier' => ['Cashier', 10]];

    /** National Living / Minimum Wage from April 2026, pence per hour: [from age, to age, rate, label]. */
    public const WAGES = [[21, null, 1271, 'National Living Wage (21 and over)'], [18, 20, 1085, '18 to 20'], [16, 17, 800, 'Under 18'], [16, null, 800, 'Apprentice']];

    /**
     * @return list<Member>
     */
    public static function of(DemoBusiness $b): array
    {
        $shops = array_map(fn (array $s) => $s['shop'], $b->shops);
        $members = [[
            'id' => $b->id('user|owner'), 'name' => DemoPeople::owner($b->company->name), 'role' => 'owner', 'kind' => 'owner',
            'rate' => 0, 'shops' => $shops, 'age' => 48, 'active' => true,
        ]];
        $offset = str_contains($b->company->name, 'Singh') ? 2 : (str_contains($b->company->name, 'Patel') ? 3 : 0);

        foreach ($shops as $i => $shop) {
            $names = DemoPeople::STAFF[($offset + $i) % count(DemoPeople::STAFF)];
            $members[] = ['id' => $shop->id('staff|manager'), 'name' => $names[0], 'role' => 'manager', 'kind' => 'manager', 'rate' => 1450, 'shops' => [$shop], 'age' => 36, 'active' => true];

            foreach ($shop->cashiers as $n => $id) {
                $members[] = [
                    'id' => $id, 'name' => $names[$n + 1], 'role' => $n === 0 ? 'supervisor' : 'cashier', 'kind' => 'cashier',
                    'rate' => $n === 0 ? 1325 : ($n === 2 ? 1085 : 1271), 'shops' => [$shop], 'age' => [29, 24, 19][$n], 'active' => true,
                ];
            }

            $members[] = ['id' => $shop->id('staff|weekend'), 'name' => $names[4], 'role' => 'cashier', 'kind' => 'weekend', 'rate' => 800, 'shops' => [$shop], 'age' => 17, 'active' => true];
            $members[] = ['id' => $shop->id('staff|leaver'), 'name' => DemoPeople::FIRST[($i * 7 + $offset) % count(DemoPeople::FIRST)].' '.DemoPeople::LAST[($i * 5 + 3) % count(DemoPeople::LAST)], 'role' => 'cashier', 'kind' => 'leaver', 'rate' => 1271, 'shops' => [$shop], 'age' => 22, 'active' => false];
        }

        return $members;
    }

    /**
     * Staff of one shop (owner included).
     *
     * @return list<Member>
     */
    public static function at(DemoBusiness $b, DemoShop $shop, bool $activeOnly = true): array
    {
        return array_values(array_filter(self::of($b), fn (array $m) => ($m['active'] || ! $activeOnly)
            && in_array($shop->branchId, array_map(fn (DemoShop $s) => $s->branchId, $m['shops']), true)));
    }

    public static function manager(DemoShop $shop): string
    {
        return $shop->id('staff|manager');
    }
}
