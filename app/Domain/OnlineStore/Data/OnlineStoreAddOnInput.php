<?php

namespace App\Domain\OnlineStore\Data;

use App\Domain\OnlineStore\Enums\StoreTemplate;

/**
 * The admin's online store add-on form (OS-6): on or off, the monthly price before VAT (pounds; null = the plan's or
 * the default `online_store.default_monthly_price`) and the store's template (null = keep the one it has).
 *
 * OS-7: `planCode` set = the business's plan switches the add-on (new tenant, Change plan): the plan's terms stand in
 * for the monthly-plan and cloud-sync checks, and the caller updates the Direct Debit itself.
 */
final readonly class OnlineStoreAddOnInput
{
    /** Owner 2026-10-24: the templates we offer when switching the add-on on. */
    public const TEMPLATES = [StoreTemplate::FreshMarket, StoreTemplate::Butcher];

    public function __construct(
        public bool $on,
        public ?string $monthlyPrice = null,
        public ?StoreTemplate $template = null,
        public ?string $planCode = null,
    ) {}

    public function byPlan(): bool
    {
        return $this->planCode !== null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function templateOptions(): array
    {
        return array_map(fn (StoreTemplate $template) => ['value' => $template->value, 'label' => $template->label()], self::TEMPLATES);
    }
}
