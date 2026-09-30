<?php

namespace App\Domain\Tenancy\Enums;

/**
 * A user's role inside one company (stored on the company_user pivot).
 */
enum CompanyRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::Accountant => 'Accountant',
            self::Staff => 'Staff',
        };
    }

    /**
     * Default permission map for the role.
     *
     * @return list<Ability>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Owner => Ability::cases(),
            self::Manager => [
                Ability::DashboardView,
                Ability::SalesView,
                Ability::CatalogueView,
                Ability::CatalogueManage,
                Ability::PricesManage,
                Ability::PromotionsManage,
                Ability::StockView,
                Ability::StockManage,
                Ability::CustomersView,
                Ability::CustomersManage,
                Ability::ReportsView,
                Ability::SettingsManage,
                Ability::SyncManage,
                Ability::StaffManage,
                Ability::SuppliersManage,
                Ability::ShopsView,
                Ability::ShopsManage,
            ],
            self::Accountant => [
                Ability::DashboardView,
                Ability::SalesView,
                Ability::ReportsView,
                Ability::BillingView,
                Ability::ShopsView,
            ],
            self::Staff => [
                Ability::DashboardView,
                Ability::SalesView,
                Ability::CatalogueView,
                Ability::StockView,
                Ability::CustomersView,
            ],
        };
    }

    public function can(Ability|string $ability): bool
    {
        $ability = $ability instanceof Ability ? $ability : Ability::tryFrom($ability);

        return $ability !== null && in_array($ability, $this->abilities(), true);
    }
}
