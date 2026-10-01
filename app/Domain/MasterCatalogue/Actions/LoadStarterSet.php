<?php

namespace App\Domain\MasterCatalogue\Actions;

use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Demo\Catalogue\DemoRange;
use App\Domain\MasterCatalogue\Enums\MasterSource;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Support\Gtin;
use App\Domain\MasterCatalogue\Support\PackSize;
use App\Domain\Reporting\Demo\DemoCatalogue;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seeds the master catalogue with the "starter set": the ~600 UK convenience lines of the demo catalogue
 * (DemoProducts), with department, category, VAT, RRP, age check and size. Most of their barcodes are generated (valid
 * check digits, not real products), so every row is marked source `starter` and shows as "Starter set" until a
 * licensed file or an admin replaces it. Safe to run again: rows an admin edited or an import updated are left alone.
 * `php artisan catalogue:starter` or the button on /admin/catalogue.
 */
final class LoadStarterSet
{
    public const SOURCE_REF = 'SSPOS starter set (demo catalogue)';

    public function __construct(private readonly SaveMasterProduct $save, private readonly RecordAudit $audit) {}

    /**
     * @return array{created: int, updated: int, unchanged: int, skipped: int}
     */
    public function handle(): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $codes = array_values(array_map(fn (array $p) => (string) $p['barcode'], DemoProducts::all()));
        $existing = MasterProduct::query()->whereIn('barcode', $codes)->get()->keyBy('barcode');

        DB::transaction(function () use (&$counts, $existing) {
            foreach (DemoProducts::all() as $p) {
                $barcode = Gtin::normalise($p['barcode']);
                $row = $barcode === null ? null : $existing->get($barcode);

                if ($barcode === null || Gtin::isInStore($barcode) || ($row !== null && $row->source !== MasterSource::Starter)) {
                    $counts['skipped']++;

                    continue;
                }

                try {
                    [, $outcome] = $this->save->handle($row, self::values($p), MasterSource::Starter, self::SOURCE_REF, audit: false);
                    $counts[$outcome]++;
                } catch (ValidationException) {
                    $counts['skipped']++;
                }
            }
        });

        $this->audit->handle('master_catalogue.starter_loaded', null, null, $counts);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $p  a DemoProducts entry
     * @return array<string, mixed>
     */
    private static function values(array $p): array
    {
        $size = PackSize::fromName((string) $p['name']);

        return [
            'barcode' => $p['barcode'],
            'name' => $p['name'],
            'size_value' => $size->value,
            'size_unit' => $size->unit,
            'pack_qty' => $size->pack,
            'department' => DemoRange::DEPARTMENTS[$p['department']][0],
            'category' => DemoRange::CATEGORIES[$p['category']][1],
            'vat_rate' => DemoCatalogue::VAT[$p['vat']],
            'rrp' => number_format((int) $p['price'] / 100, 2, '.', ''),
            'age_rule' => $p['ageRule'],
            'in_starter_packs' => true,
        ];
    }
}
