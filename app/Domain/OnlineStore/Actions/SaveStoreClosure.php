<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStoreClosure;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\OnlineStore\Support\WeekHours;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A closed day or special hours for one of the store's shops (audit OSA-13, portal → Online store → Collection
 * shops, `store.manage`): a date from today up to a year ahead, closed all day or open only between two times, and a
 * reason customers see ("Christmas Day"). One per shop and date (saving again changes it). Orders already placed for
 * that day stay; the board shows them as usual. Audited. `remove()` deletes one.
 */
final class SaveStoreClosure
{
    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array<string, mixed>  $input  date (Y-m-d), all_day, opens_at, closes_at, reason
     *
     * @throws ValidationException
     */
    public function handle(Company $company, string $branchId, array $input): OnlineStoreClosure
    {
        $today = CarbonImmutable::now(OnlineStoreSwitch::timezone())->format('Y-m-d');
        $data = Validator::make($input, [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before:'.CarbonImmutable::parse($today)->addYear()->format('Y-m-d')],
            'all_day' => ['required', 'boolean'],
            'opens_at' => ['nullable', 'string'],
            'closes_at' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:120'],
        ], [
            'date.required' => 'Choose the date.',
            'date.date_format' => 'Choose the date.',
            'date.after_or_equal' => 'Choose today or a later date.',
            'date.before' => 'Choose a date within the next year.',
        ])->validate();

        $hours = (bool) $data['all_day'] ? null : WeekHours::pair((string) ($data['opens_at'] ?? ''), (string) ($data['closes_at'] ?? ''), 'hours');

        return $this->tenancy->runAs($company, function () use ($company, $branchId, $data, $hours): OnlineStoreClosure {
            $shop = self::shop($company, $branchId);
            $closure = OnlineStoreClosure::query()->firstOrNew(['company_id' => $company->id, 'online_store_shop_id' => $shop->id, 'date' => $data['date']]);
            $closure->fill([
                'opens_at' => $hours['open'] ?? null,
                'closes_at' => $hours['close'] ?? null,
                'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
            ])->save();

            $this->audit->handle('online_store.closure_saved', $closure, null, [
                'date' => $closure->date, 'hours' => $hours, 'reason' => $closure->reason,
            ], ['shop' => $shop->branch->name], companyId: $company->id);

            return $closure;
        });
    }

    public function remove(Company $company, string $branchId, string $closureId): void
    {
        $this->tenancy->runAs($company, function () use ($company, $branchId, $closureId): void {
            $shop = self::shop($company, $branchId);
            $closure = OnlineStoreClosure::query()->where('company_id', $company->id)->where('online_store_shop_id', $shop->id)->whereKey($closureId)->first()
                ?? throw ValidationException::withMessages(['closure' => 'That closed day was not found.']);
            $closure->delete();

            $this->audit->handle('online_store.closure_removed', $closure, ['date' => $closure->date], null, ['shop' => $shop->branch->name], companyId: $company->id);
        });
    }

    /**
     * @throws ValidationException
     */
    private static function shop(Company $company, string $branchId): OnlineStoreShop
    {
        $store = StoreScope::storeOf($company);

        return OnlineStoreShop::query()->with('branch')->where('company_id', $company->id)->where('online_store_id', $store->id)->where('branch_id', $branchId)->first()
            ?? throw ValidationException::withMessages(['branch_id' => 'Add this shop as a collection shop first.']);
    }
}
