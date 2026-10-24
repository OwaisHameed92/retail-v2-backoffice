<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Actions\SaveStoreLegal;
use App\Domain\OnlineStore\Legal\LegalPage;
use App\Domain\OnlineStore\Legal\StoreTrader;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\StoreAddress;
use App\Domain\Tenancy\Models\Company;

/**
 * The portal's "Legal & contact" tab (OS-11, audit OSA-2): the store's saved trader details, else the business
 * record's (StoreTrader::suggested) as the form's starting values, which details are still to save before going live,
 * the shop's own extra text per legal page, and each page's public address to preview. No store → `hasStore` false.
 */
final class StoreLegalPage
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, bool $canEdit): array
    {
        $store = OnlineStore::query()->where('company_id', $company->id)->first();
        $suggested = StoreTrader::suggested($company);
        $details = [];

        foreach (StoreTrader::FIELDS as $field) {
            $saved = $store?->getAttribute($field);
            $details[$field] = is_string($saved) && trim($saved) !== '' ? $saved : ($suggested[$field] ?? null);
        }

        $extra = is_array($store?->legal_extra) ? $store->legal_extra : [];
        $address = $store === null ? null : rtrim(StoreAddress::of($store), '/');

        return [
            'hasStore' => $store !== null,
            'canEdit' => $canEdit,
            'details' => $details,
            'saved' => $store !== null && StoreTrader::missing($store) === [],
            'missing' => $store === null ? array_values(StoreTrader::REQUIRED) : StoreTrader::missing($store),
            'extra' => array_combine(LegalPage::values(), array_map(fn (string $page) => (string) ($extra[$page] ?? ''), LegalPage::values())),
            'extraMax' => SaveStoreLegal::EXTRA_MAX,
            'pages' => array_map(fn (LegalPage $page) => ['key' => $page->value, 'label' => $page->label(), 'url' => $address === null ? null : "{$address}/{$page->value}"],
                LegalPage::cases()),
            'isLive' => (bool) $store?->is_live,
        ];
    }
}
