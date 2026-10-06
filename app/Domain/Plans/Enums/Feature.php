<?php

namespace App\Domain\Plans\Enums;

use App\Domain\Shared\Country\Country;

/**
 * Product features a plan or a branch licence can switch on. The values are **exactly the till's 11 feature
 * names** (`Feature.cs`, contract v1.4.1 ANSWERS §6): they go into the licence token as they are, so they are
 * snake_case, not our usual camelCase (DECISIONS "Module 2.1"). Stored in `plans.features`, `licences.features`
 * and `branches.licence_features` as JSON lists. `multi_branch` in a token follows the company's multi-branch
 * setting, not the plan.
 */
enum Feature: string
{
    case Loyalty = 'loyalty';
    case Promotions = 'promotions';
    case Purchasing = 'purchasing';
    case Accounts = 'accounts';
    case MultiBranch = 'multi_branch';
    case SecondScreen = 'second_screen';
    case LabelPrinting = 'label_printing';
    case CloudSync = 'cloud_sync';
    case Assist = 'assist';
    case AssistInvoiceScan = 'assist_invoice_scan';
    case AssistQuestions = 'assist_questions';

    public function label(): string
    {
        return match ($this) {
            self::Loyalty => 'Loyalty',
            self::Promotions => 'Promotions',
            self::Purchasing => 'Purchasing',
            self::Accounts => Country::tax('Accounts and VAT'),
            self::MultiBranch => 'Multi-branch',
            self::SecondScreen => 'Second screen',
            self::LabelPrinting => 'Label printing',
            self::CloudSync => 'Online dashboard (cloud sync)',
            self::Assist => 'Assist',
            self::AssistInvoiceScan => 'Assist: invoice scan',
            self::AssistQuestions => 'Assist: questions',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Loyalty => 'Customer points, balances and rewards at the till.',
            self::Promotions => 'Multi-buys, meal deals, coupons and price promotions.',
            self::Purchasing => 'Suppliers, purchase orders, deliveries and supplier invoices.',
            self::Accounts => Country::tax('Expenses, VAT returns, profit and loss, and journals.'),
            self::MultiBranch => 'Run several shops from one account, with transfers and branch reports.',
            self::SecondScreen => 'A customer-facing display showing the basket and offers.',
            self::LabelPrinting => 'Shelf-edge labels and barcode labels from the till.',
            self::CloudSync => 'The till syncs with the online dashboard; the branch gets a sync key.',
            self::Assist => 'AI help: morning summaries, reorder suggestions and alerts on unusual activity.',
            self::AssistInvoiceScan => 'AI reads supplier invoices and turns them into deliveries.',
            self::AssistQuestions => 'Ask about sales and stock in plain English.',
        };
    }

    public function isAi(): bool
    {
        return $this === self::Assist || $this === self::AssistInvoiceScan || $this === self::AssistQuestions;
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
