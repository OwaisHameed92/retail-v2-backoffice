<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\DeliveryArea;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\OnlineStore\Support\WeekHours;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * One collection shop's local delivery (OS-9, portal → Online store → Delivery & payments, `store.manage`): on or
 * off, the area (postcode districts / sectors / postcodes: DeliveryArea; no radius, the codebase has no postcode →
 * map source), the fee, free delivery from an items total, the minimum order, and its own slots: hours per weekday,
 * slot length, orders per slot, and lead time (the first slot starts at least this long after the order). Money in
 * pounds, 2 dp. The shop must already be one of the store's collection shops. Turning it on needs an area. Audited.
 */
final class SaveDeliverySettings
{
    private const MONEY = '/^\d{1,6}(\.\d{1,2})?$/';

    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(Company $company, string $branchId, array $input): OnlineStoreShop
    {
        $data = self::validate($input);

        return $this->tenancy->runAs($company, function () use ($company, $branchId, $data): OnlineStoreShop {
            $store = StoreScope::storeOf($company);
            $shop = OnlineStoreShop::query()->with('branch')->where('company_id', $company->id)->where('online_store_id', $store->id)
                ->where('branch_id', $branchId)->first()
                ?? throw ValidationException::withMessages(['branch_id' => 'Add this shop as a collection shop first.']);

            $before = self::snapshot($shop);
            $shop->forceFill($data)->save();
            $after = self::snapshot($shop);

            if ($before !== $after) {
                $this->audit->handle('online_store.delivery_updated', $shop, $before, $after, ['shop' => $shop->branch->name], companyId: $company->id);
            }

            return $shop->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'delivery_enabled' => ['required', 'boolean'],
            'delivery_areas' => ['nullable'],
            'delivery_fee' => ['required', 'regex:'.self::MONEY],
            'delivery_free_over' => ['nullable', 'regex:'.self::MONEY],
            'delivery_minimum' => ['nullable', 'regex:'.self::MONEY],
            'delivery_hours' => ['present'],
            'delivery_slot_minutes' => ['required', 'integer', 'min:15', 'max:240', 'multiple_of:5'],
            'delivery_orders_per_slot' => ['required', 'integer', 'min:1', 'max:100'],
            'delivery_lead_minutes' => ['required', 'integer', 'min:0', 'max:2880'],
        ], [
            'delivery_fee.required' => 'Enter the delivery fee (0 for free delivery).',
            'regex' => 'Enter an amount like 2.50.',
            'delivery_slot_minutes.*' => 'Choose a slot length between 15 minutes and 4 hours.',
            'delivery_orders_per_slot.*' => 'Enter 1 to 100 orders per slot.',
            'delivery_lead_minutes.*' => 'Enter 0 to 2880 minutes (2 days).',
        ])->validate();

        $parsed = DeliveryArea::parse(is_array($data['delivery_areas'] ?? null) ? array_map('strval', $data['delivery_areas']) : (string) ($data['delivery_areas'] ?? ''));

        if ($parsed['invalid'] !== []) {
            throw ValidationException::withMessages(['delivery_areas' => 'These are not postcode areas: '.implode(', ', array_slice($parsed['invalid'], 0, 5)).'. Use districts like B23 or sectors like B24 9.']);
        }

        if (count($parsed['areas']) > DeliveryArea::MAX_AREAS) {
            throw ValidationException::withMessages(['delivery_areas' => 'Keep to '.DeliveryArea::MAX_AREAS.' areas or fewer.']);
        }

        $enabled = (bool) $data['delivery_enabled'];
        $hours = WeekHours::clean($data['delivery_hours'], 'delivery_hours');

        if ($enabled && $parsed['areas'] === []) {
            throw ValidationException::withMessages(['delivery_areas' => 'Add the postcode areas you deliver to.']);
        }

        if ($enabled && array_filter($hours) === []) {
            throw ValidationException::withMessages(['delivery_hours' => 'Choose at least one day you deliver.']);
        }

        $money = fn (mixed $value): ?string => $value === null || $value === '' ? null : Money::normalise((string) $value, 2);

        return [
            'delivery_enabled' => $enabled,
            'delivery_areas' => $parsed['areas'],
            'delivery_fee' => Money::normalise((string) $data['delivery_fee'], 2),
            'delivery_free_over' => $money($data['delivery_free_over'] ?? null),
            'delivery_minimum' => $money($data['delivery_minimum'] ?? null),
            'delivery_hours' => $hours,
            'delivery_slot_minutes' => (int) $data['delivery_slot_minutes'],
            'delivery_orders_per_slot' => (int) $data['delivery_orders_per_slot'],
            'delivery_lead_minutes' => (int) $data['delivery_lead_minutes'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(OnlineStoreShop $shop): array
    {
        return [
            'delivery_enabled' => (bool) $shop->delivery_enabled, 'delivery_areas' => $shop->delivery_areas, 'delivery_fee' => $shop->delivery_fee,
            'delivery_free_over' => $shop->delivery_free_over, 'delivery_minimum' => $shop->delivery_minimum, 'delivery_hours' => $shop->delivery_hours,
            'delivery_slot_minutes' => (int) $shop->delivery_slot_minutes, 'delivery_orders_per_slot' => (int) $shop->delivery_orders_per_slot,
            'delivery_lead_minutes' => (int) $shop->delivery_lead_minutes,
        ];
    }
}
