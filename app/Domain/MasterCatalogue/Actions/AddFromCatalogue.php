<?php

namespace App\Domain\MasterCatalogue\Actions;

use App\Domain\Catalogue\Actions\SaveCategory;
use App\Domain\Catalogue\Actions\SaveDepartment;
use App\Domain\Catalogue\Actions\SaveProduct;
use App\Domain\Catalogue\Import\ImportLookups;
use App\Domain\MasterCatalogue\Data\PriceRule;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Support\Gtin;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\VatRate;
use Illuminate\Validation\ValidationException;

/**
 * Copies master catalogue products into the current business's own catalogue ("Add from catalogue", starter packs).
 * Every product is created through SaveProduct (hub-owned rows: every till gets it at its next pull), each on its
 * own, so one failure never undoes the rest. Barcodes the business already has are skipped, never duplicated.
 *
 * Department: the business department the owner mapped the catalogue's department to, or (no mapping) a department of
 * that name, found or created. Category: the catalogue's category in that department, found or created. VAT: the
 * business's rate of the catalogue's percentage, else the category's / department's / business default. Price:
 * PriceRule. Cost: as typed, else 0 for the owner to fill in.
 */
final class AddFromCatalogue
{
    public const MAX_ITEMS = 2000;

    public function __construct(
        private readonly SaveProduct $save,
        private readonly SaveDepartment $saveDepartment,
        private readonly SaveCategory $saveCategory,
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  list<array{barcode: string, sell_price?: string|null, cost_price?: string|null}>  $items
     * @param  array<string, string|null>  $departments  catalogue department name => business department id (null/'' = by name)
     * @return array{created: int, existing: list<string>, failed: list<array{barcode: string, name: string, message: string}>}
     */
    public function handle(array $items, PriceRule $rule, array $departments = [], string $via = 'catalogue'): array
    {
        $companyId = (string) $this->tenancy->require()->id;

        if (! VatRate::query()->exists()) {
            throw ValidationException::withMessages(['items' => Country::tax('Your VAT rates arrive from your till at its first sync. Connect a till first, then add products.')]);
        }

        $this->checkDepartments($departments);
        $wanted = $this->wanted($items);
        $masters = $this->masters(array_keys($wanted));
        $existing = $this->existing(array_keys($masters));
        $lookups = ImportLookups::load();
        $vat = VatRate::query()->pluck('percentage', 'id')->all();
        $result = ['created' => 0, 'existing' => [], 'failed' => []];
        $done = [];

        foreach ($masters as $code => $master) {
            $code = (string) $code;

            if (isset($existing[$code]) || isset($done[$master->id])) {
                $result['existing'][] = $code;

                continue;
            }

            try {
                $attributes = $this->attributes($master, $wanted[$code], $rule, $departments, $lookups, $vat);
                $this->save->handle(null, $attributes, [['barcode' => $code, 'pack_qty' => 1, 'is_primary' => true]], null, audit: false);
                $result['created']++;
                $done[$master->id] = true;
            } catch (ValidationException $e) {
                $result['failed'][] = ['barcode' => $code, 'name' => $master->name, 'message' => (string) collect($e->errors())->flatten()->first()];
            }
        }

        $this->audit->handle('catalogue.products_added', null, null, [
            'created' => $result['created'], 'skipped' => count($result['existing']), 'failed' => count($result['failed']),
        ], ['via' => $via, 'priceRule' => $rule->mode], null, $companyId);

        return $result;
    }

    /**
     * @param  array{barcode: string, sell_price?: string|null, cost_price?: string|null}  $item
     * @param  array<string, string|null>  $departments
     * @param  array<string, mixed>  $vat  VAT rate id => percentage
     * @return array<string, mixed>
     */
    private function attributes(MasterProduct $master, array $item, PriceRule $rule, array $departments, ImportLookups $lookups, array $vat): array
    {
        $departmentName = $master->department ?? 'General';
        $departmentId = ($departments[$departmentName] ?? '') ?: $lookups->ensureDepartment($departmentName, $this->saveDepartment);
        $categoryId = $lookups->ensureCategory($departmentId, $master->category ?? $departmentName, $this->saveCategory);
        $vatId = ($master->vat_rate !== null ? $lookups->vatId((string) $master->vat_rate) : null) ?? $lookups->defaultVat($departmentId, $categoryId);
        $cost = isset($item['cost_price']) && is_numeric($item['cost_price']) ? number_format((float) $item['cost_price'], 4, '.', '') : null;
        $price = $rule->sellPrice($item['sell_price'] ?? null, $cost, $master->rrp, (float) ($vat[$vatId] ?? 0));

        if ($price === null) {
            throw ValidationException::withMessages(['sell_price' => 'No price: it has no RRP, so type a sell price or a cost.']);
        }

        $size = $master->size();

        return [
            'name' => $master->name, 'brand' => $master->brand, 'department_id' => $departmentId, 'category_id' => $categoryId,
            'vat_rate_id' => $vatId, 'sell_price' => $price, 'cost_price' => $cost ?? '0', 'age_rule' => $master->age_rule,
            'is_tobacco' => $master->age_rule === 'tobaccoGenerational', 'volume_ml' => $size->volumeMl(), 'net_mass_kg' => $size->massKg(),
        ];
    }

    /**
     * @param  array<string, string|null>  $departments
     */
    private function checkDepartments(array $departments): void
    {
        $ids = array_values(array_filter($departments));
        $known = $ids === [] ? [] : Department::query()->whereIn('id', $ids)->pluck('id')->all();

        foreach ($departments as $name => $id) {
            if ($id !== null && $id !== '' && ! in_array($id, $known, true)) {
                throw ValidationException::withMessages(['departments' => "Choose one of your departments for {$name}."]);
            }
        }
    }

    /**
     * @param  list<array{barcode: string, sell_price?: string|null, cost_price?: string|null}>  $items
     * @return array<string, array{barcode: string, sell_price?: string|null, cost_price?: string|null}>
     */
    private function wanted(array $items): array
    {
        $wanted = [];

        foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
            if (($code = Gtin::normalise($item['barcode'])) !== null) {
                $wanted[$code] = $item;
            }
        }

        return $wanted;
    }

    /**
     * The catalogue rows by barcode (a merged barcode resolves to the product it was merged into, under its own code).
     *
     * @param  list<int|string>  $codes
     * @return array<string, MasterProduct>
     */
    private function masters(array $codes): array
    {
        $codes = array_map('strval', $codes);
        $found = MasterProduct::query()->whereIn('barcode', $codes)->get();
        $targets = MasterProduct::query()->whereIn('id', $found->pluck('merged_into_id')->filter()->unique()->values())->get()->keyBy('id');
        $masters = [];

        foreach ($found as $row) {
            $masters[$row->barcode] = $row->merged_into_id === null ? $row : ($targets->get($row->merged_into_id) ?? $row);
        }

        return $masters;
    }

    /**
     * Barcodes (or their UPC/EAN twin) already on one of this business's products.
     *
     * @param  list<int|string>  $codes
     * @return array<string, true>
     */
    private function existing(array $codes): array
    {
        $variants = [];

        foreach ($codes as $code) {
            foreach (Gtin::variants((string) $code) as $variant) {
                $variants[$variant] = (string) $code;
            }
        }

        $existing = [];

        foreach (array_chunk(array_map('strval', array_keys($variants)), 500) as $chunk) {
            foreach (ProductBarcode::query()->whereIn('barcode', $chunk)->pluck('barcode') as $barcode) {
                $existing[$variants[$barcode]] = true;
            }
        }

        return $existing;
    }
}
