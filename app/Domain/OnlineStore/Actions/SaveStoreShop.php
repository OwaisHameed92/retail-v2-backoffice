<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Adds or changes one of the business's shops as a collection shop of its store: opening hours per weekday (shop
 * time, `{"open": "08:00", "close": "20:00"}` or null for no collections that day), minutes to get an order ready,
 * slot length, orders per slot, and whether it takes collections at all. The shop must be one of this business's
 * open shops. One row per shop (upsert). Audited.
 *
 *     app(SaveStoreShop::class)->handle($company, $branchId, ['opening_hours' => [...], 'prep_minutes' => 30, ...]);
 */
final class SaveStoreShop
{
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
            $branch = Branch::query()->where('company_id', $company->id)->whereKey($branchId)->first();

            if ($branch === null || ! $branch->is_active) {
                throw ValidationException::withMessages(['branch_id' => 'Choose one of your open shops.']);
            }

            $shop = OnlineStoreShop::query()->where('company_id', $company->id)->where('online_store_id', $store->id)->where('branch_id', $branch->id)->first();
            $before = $shop === null ? null : self::snapshot($shop);
            $shop ??= new OnlineStoreShop(['company_id' => $company->id, 'online_store_id' => $store->id, 'branch_id' => $branch->id]);
            $shop->fill($data)->save();
            $after = self::snapshot($shop);

            if ($before !== $after) {
                $this->audit->handle($before === null ? 'online_store.shop_added' : 'online_store.shop_updated', $shop, $before, $after,
                    ['shop' => $branch->name], companyId: $company->id);
            }

            return $shop->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{opening_hours: array<string, array{open: string, close: string}|null>, prep_minutes: int, slot_minutes: int, orders_per_slot: int, enabled: bool}
     *
     * @throws ValidationException
     */
    private static function validate(array $input): array
    {
        $time = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';
        $rules = [
            'opening_hours' => ['present', 'array'],
            'prep_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'slot_minutes' => ['required', 'integer', 'min:10', 'max:240', 'multiple_of:5'],
            'orders_per_slot' => ['required', 'integer', 'min:1', 'max:100'],
            'enabled' => ['required', 'boolean'],
        ];

        foreach (OnlineStoreShop::WEEKDAYS as $day) {
            $rules["opening_hours.{$day}"] = ['nullable', 'array'];
            $rules["opening_hours.{$day}.open"] = ["required_with:opening_hours.{$day}", 'string', $time];
            $rules["opening_hours.{$day}.close"] = ["required_with:opening_hours.{$day}", 'string', $time];
        }

        $data = Validator::make($input, $rules, ['regex' => 'Use a time like 08:30.'])->validate();
        $hours = [];

        foreach (OnlineStoreShop::WEEKDAYS as $day) {
            $value = $data['opening_hours'][$day] ?? null;

            if (! is_array($value) || ! isset($value['open'], $value['close'])) {
                $hours[$day] = null;

                continue;
            }

            if (strcmp((string) $value['close'], (string) $value['open']) <= 0) {
                throw ValidationException::withMessages(["opening_hours.{$day}.close" => 'Closing time must be after opening time.']);
            }

            $hours[$day] = ['open' => (string) $value['open'], 'close' => (string) $value['close']];
        }

        return [
            'opening_hours' => $hours,
            'prep_minutes' => (int) $data['prep_minutes'],
            'slot_minutes' => (int) $data['slot_minutes'],
            'orders_per_slot' => (int) $data['orders_per_slot'],
            'enabled' => (bool) $data['enabled'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(OnlineStoreShop $shop): array
    {
        return [
            'opening_hours' => $shop->opening_hours,
            'prep_minutes' => (int) $shop->prep_minutes,
            'slot_minutes' => (int) $shop->slot_minutes,
            'orders_per_slot' => (int) $shop->orders_per_slot,
            'enabled' => (bool) $shop->enabled,
        ];
    }
}
