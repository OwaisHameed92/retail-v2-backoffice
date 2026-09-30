<?php

namespace App\Domain\Staff\Support;

use App\Domain\TillData\Models\TillRole;
use App\Domain\TillData\Models\TillRolePermission;
use Illuminate\Support\Str;

/**
 * The till permissions the role editor lists (module 4.5, contract §10.3). The till's own catalogue
 * (`src/SSPOS.Domain/People/Permissions.cs`) is not in the contract, so the list is the keys the contract names
 * (with our wording) plus every key this business's tills have sent: a till pushes every permission it holds once
 * on upgrade and after its history upload, so a synced business sees its tills' full set. Only listed keys can be
 * granted, so the portal never invents a permission a till does not know. Call inside the company scope.
 */
final class TillPermissionCatalogue
{
    /** @var array<string, array{0: string, 1: string}> key => [label, help] */
    public const KNOWN = [
        'sale.refund' => ['Give refunds', 'Refund a sale or an item from a sale.'],
        'sale.no_sale' => ['Open the drawer without a sale', 'The "No sale" button.'],
        'sale.view_all_orders' => ['See every staff member\'s sales', 'Without it, receipts, the order list and refund look-ups show only their own sales.'],
        'business.apply_all_shops' => ['Apply changes at every shop', 'Set a price, offer or standing discount for every shop, not just this one.'],
    ];

    /** @var array<string, string> */
    private const GROUPS = ['sale' => 'Selling', 'business' => 'Business', 'stock' => 'Stock', 'cash' => 'Cash', 'report' => 'Reports'];

    /** @return list<string> every key the editor may grant, sorted */
    public function keys(): array
    {
        $keys = array_keys(self::KNOWN);
        $keys = [...$keys, ...TillRolePermission::withTrashed()->distinct()->pluck('permission_key')->all()];

        foreach (TillRole::query()->get(['id', 'permissions']) as $role) {
            foreach ($role->permissions ?? [] as $key) {
                if (is_string($key)) {
                    $keys[] = $key;
                }
            }
        }

        $keys = array_values(array_unique(array_filter($keys, fn ($k) => is_string($k) && preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $k) === 1)));
        sort($keys);

        return $keys;
    }

    /**
     * The keys grouped for the editor.
     *
     * @return list<array{label: string, permissions: list<array{key: string, label: string, help: string|null}>}>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->keys() as $key) {
            $prefix = Str::before($key, '.');
            $label = self::GROUPS[$prefix] ?? Str::headline($prefix);
            $groups[$label][] = [
                'key' => $key,
                'label' => self::KNOWN[$key][0] ?? Str::ucfirst(str_replace(['_', '.'], ' ', Str::after($key, '.'))),
                'help' => self::KNOWN[$key][1] ?? null,
            ];
        }

        ksort($groups);

        return array_map(fn ($label, $permissions) => ['label' => $label, 'permissions' => $permissions], array_keys($groups), array_values($groups));
    }
}
