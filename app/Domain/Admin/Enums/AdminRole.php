<?php

namespace App\Domain\Admin\Enums;

/**
 * Role of an SSPOS staff member in the super admin area.
 *
 * Abilities (checked with AdminRole::can() / Admin::hasAbility()):
 *
 * | Ability           | owner | sales | support | accounts |
 * |-------------------|-------|-------|---------|----------|
 * | admins.manage     |  yes  |       |         |          |
 * | tenants.view      |  yes  |  yes  |   yes   |   yes    |
 * | tenants.manage    |  yes  |  yes  |   yes   |          |
 * | licences.manage   |  yes  |       |   yes   |          |
 * | billing.manage    |  yes  |       |         |   yes    |
 * | leads.manage      |  yes  |  yes  |         |          |
 * | trading.view      |  yes  |       |   yes   |   yes    |
 * | audit.view        |  yes  |       |   yes   |          |
 * | catalogue.manage  |  yes  |       |   yes   |          |
 *
 * The owner can do everything. Unknown abilities are denied for every role except owner.
 */
enum AdminRole: string
{
    case Owner = 'owner';
    case Sales = 'sales';
    case Support = 'support';
    case Accounts = 'accounts';

    public const ADMINS_MANAGE = 'admins.manage';

    public const TENANTS_VIEW = 'tenants.view';

    public const TENANTS_MANAGE = 'tenants.manage';

    public const LICENCES_MANAGE = 'licences.manage';

    public const BILLING_MANAGE = 'billing.manage';

    public const LEADS_MANAGE = 'leads.manage';

    /** Module 3.2: customers' shop sales (the admin trading dashboard). Not for sales staff. */
    public const TRADING_VIEW = 'trading.view';

    /** The audit log across every business (/admin/audit-log). Owner and support. */
    public const AUDIT_VIEW = 'audit.view';

    /** The platform-wide master catalogue (/admin/catalogue): products, CSV loads, merges, the till review queue. */
    public const CATALOGUE_MANAGE = 'catalogue.manage';

    /**
     * Abilities granted to each non-owner role. The owner implicitly has all abilities.
     *
     * @var array<string, list<string>>
     */
    private const ABILITIES = [
        'sales' => [self::TENANTS_VIEW, self::TENANTS_MANAGE, self::LEADS_MANAGE],
        'support' => [self::TENANTS_VIEW, self::TENANTS_MANAGE, self::LICENCES_MANAGE, self::TRADING_VIEW, self::AUDIT_VIEW, self::CATALOGUE_MANAGE],
        'accounts' => [self::TENANTS_VIEW, self::BILLING_MANAGE, self::TRADING_VIEW],
    ];

    public function can(string $ability): bool
    {
        if ($this === self::Owner) {
            return true;
        }

        return in_array($ability, self::ABILITIES[$this->value], true);
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        if ($this === self::Owner) {
            return self::allAbilities();
        }

        return self::ABILITIES[$this->value];
    }

    /**
     * @return list<string>
     */
    public static function allAbilities(): array
    {
        return [
            self::ADMINS_MANAGE,
            self::TENANTS_VIEW,
            self::TENANTS_MANAGE,
            self::LICENCES_MANAGE,
            self::BILLING_MANAGE,
            self::LEADS_MANAGE,
            self::TRADING_VIEW,
            self::AUDIT_VIEW,
            self::CATALOGUE_MANAGE,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Sales => 'Sales',
            self::Support => 'Support',
            self::Accounts => 'Accounts',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $role) => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }
}
