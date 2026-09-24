<?php

namespace App\Domain\Plans\Enums;

/**
 * Product features a plan can switch on. Values are camelCase (contract style) and are stored in
 * `plans.features` as a JSON list. The till and the portal read these to show or hide whole areas.
 */
enum Feature: string
{
    case StockControl = 'stockControl';
    case Purchasing = 'purchasing';
    case CashOffice = 'cashOffice';
    case Accounts = 'accounts';
    case Staff = 'staff';
    case CustomerOrders = 'customerOrders';
    case NewsDeliveries = 'newsDeliveries';
    case MultiBranch = 'multiBranch';
    case AiAssistant = 'aiAssistant';
    case AiInsights = 'aiInsights';

    public function label(): string
    {
        return match ($this) {
            self::StockControl => 'Stock control',
            self::Purchasing => 'Purchasing',
            self::CashOffice => 'Cash office',
            self::Accounts => 'Accounts and VAT',
            self::Staff => 'Staff',
            self::CustomerOrders => 'Customer orders',
            self::NewsDeliveries => 'News deliveries',
            self::MultiBranch => 'Multi-branch',
            self::AiAssistant => 'AI assistant',
            self::AiInsights => 'AI insights',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::StockControl => 'Stock levels per branch, stock takes, movements and expiry checks.',
            self::Purchasing => 'Suppliers, purchase orders, deliveries and supplier invoices.',
            self::CashOffice => 'Shifts, Z reports, cash-up variances and card settlement.',
            self::Accounts => 'Expenses, VAT returns, profit and loss, and journals.',
            self::Staff => 'Till users, clock in and out, rotas and timesheets.',
            self::CustomerOrders => 'Take and track customer orders and deposits.',
            self::NewsDeliveries => 'Newspaper and magazine rounds, deliveries and returns.',
            self::MultiBranch => 'Run several shops from one account, with transfers and branch reports.',
            self::AiAssistant => 'Ask about sales and stock in plain English and make changes with confirmation.',
            self::AiInsights => 'Morning summaries, reorder suggestions and alerts on unusual voids and refunds.',
        };
    }

    public function isAi(): bool
    {
        return $this === self::AiAssistant || $this === self::AiInsights;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $feature) => $feature->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $feature) => ['value' => $feature->value, 'label' => $feature->label(), 'description' => $feature->description()],
            self::cases(),
        );
    }

    /**
     * Turn values into features in enum order, dropping unknown values and duplicates.
     *
     * @param  iterable<mixed>  $values
     * @return list<self>
     */
    public static function normalise(iterable $values): array
    {
        $wanted = [];

        foreach ($values as $value) {
            $feature = $value instanceof self ? $value : (is_string($value) ? self::tryFrom($value) : null);

            if ($feature !== null) {
                $wanted[$feature->value] = true;
            }
        }

        return array_values(array_filter(self::cases(), fn (self $feature) => isset($wanted[$feature->value])));
    }
}
