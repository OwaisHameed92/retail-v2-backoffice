<?php

namespace App\Domain\PortalUsers\Support;

use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\CompanyRole;

/**
 * The read-only "what each role can do" table (module 4.1), built from CompanyRole::abilities(), the single source of
 * truth for permissions. Labels are for people; a new Ability without a label here shows its key.
 */
final class RoleMatrix
{
    /** @var array<string, array{string, string}> ability value → [group, label] */
    private const LABELS = [
        'dashboard.view' => ['Overview', 'See the dashboard'],
        'sales.view' => ['Selling', 'See sales and receipts'],
        'customers.view' => ['Selling', 'See customers'],
        'customers.manage' => ['Selling', 'Add and edit customers'],
        'promotions.manage' => ['Selling', 'Manage promotions'],
        'catalogue.view' => ['Catalogue', 'See products'],
        'catalogue.manage' => ['Catalogue', 'Add and edit products'],
        'prices.manage' => ['Catalogue', 'Change prices'],
        'stock.view' => ['Catalogue', 'See stock'],
        'stock.manage' => ['Catalogue', 'Adjust stock'],
        'purchasing.view' => ['Catalogue', 'See orders, deliveries, supplier invoices and statements'],
        'purchasing.manage' => ['Catalogue', 'Draft and send head-office orders'],
        'reports.view' => ['Money', 'See reports'],
        'billing.view' => ['Money', 'See billing and invoices'],
        'billing.manage' => ['Money', 'Set up the Direct Debit'],
        'settings.manage' => ['Business', 'Change shop settings'],
        'sync.manage' => ['Business', 'Settle sync conflicts'],
        'users.manage' => ['Business', 'Invite and manage portal users'],
        'shops.view' => ['Business', 'See shops, tills and licences'],
        'shops.manage' => ['Business', 'Edit shop details and ask for more tills'],
        'business.manage' => ['Business', 'Edit the business details'],
    ];

    /** One line per role for pickers and the matrix header. */
    public static function roleHelp(CompanyRole $role): string
    {
        return match ($role) {
            CompanyRole::Owner => 'Everything, including portal users and billing. Always every shop.',
            CompanyRole::Manager => 'Runs the shops: products, prices, stock, customers, reports and settings.',
            CompanyRole::Accountant => 'Sales, reports and billing, read only.',
            CompanyRole::Staff => 'Read-only views of sales, products, stock and customers.',
        };
    }

    /**
     * @return list<array{value: string, label: string, help: string, canLimitToShop: bool}>
     */
    public static function roles(): array
    {
        return array_map(fn (CompanyRole $role) => [
            'value' => $role->value,
            'label' => $role->label(),
            'help' => self::roleHelp($role),
            'canLimitToShop' => $role !== CompanyRole::Owner,
        ], CompanyRole::cases());
    }

    /**
     * @return list<array{key: string, group: string, label: string, roles: array<string, bool>}>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::LABELS as $key => [$group, $label]) {
            $rows[] = self::row(Ability::from($key), $group, $label);
        }

        foreach (Ability::cases() as $ability) {
            if (! array_key_exists($ability->value, self::LABELS)) {
                $rows[] = self::row($ability, 'Other', $ability->value);
            }
        }

        return $rows;
    }

    /**
     * @return array{key: string, group: string, label: string, roles: array<string, bool>}
     */
    private static function row(Ability $ability, string $group, string $label): array
    {
        $roles = [];

        foreach (CompanyRole::cases() as $role) {
            $roles[$role->value] = $role->can($ability);
        }

        return ['key' => $ability->value, 'group' => $group, 'label' => $label, 'roles' => $roles];
    }
}
