<?php

namespace App\Domain\MasterCatalogue\Actions;

use App\Domain\MasterCatalogue\Enums\ContributionStatus;
use App\Domain\MasterCatalogue\Models\CatalogueContribution;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Support\Gtin;
use App\Domain\MasterCatalogue\Support\PackSize;
use Carbon\CarbonImmutable;

/**
 * Stores unknown barcodes from tills in the review queue: a new barcode becomes a pending contribution (name and size
 * as the first till called it), a barcode already queued counts one more sighting (busier barcodes are reviewed
 * first). Barcodes that reached the catalogue meanwhile are skipped.
 */
final class RecordContributions
{
    /**
     * @param  list<array{barcode: string, name: string}>  $items
     */
    public function handle(array $items): void
    {
        $byCode = [];

        foreach ($items as $item) {
            $code = Gtin::normalise($item['barcode']);
            $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $item['name']));

            if ($code !== null && ! Gtin::isInStore($code) && $name !== '') {
                $byCode[$code] ??= mb_substr($name, 0, 255);
            }
        }

        if ($byCode === []) {
            return;
        }

        $variants = array_merge(...array_map(fn ($code) => Gtin::variants((string) $code), array_keys($byCode)));
        $known = array_flip(MasterProduct::query()->whereIn('barcode', $variants)->pluck('barcode')->all());
        $queued = CatalogueContribution::query()->whereIn('barcode', array_map('strval', array_keys($byCode)))->get()->keyBy('barcode');
        $now = CarbonImmutable::now('UTC');

        foreach ($byCode as $code => $name) {
            $code = (string) $code;

            if (array_filter(Gtin::variants($code), fn (string $v) => isset($known[$v])) !== []) {
                continue;
            }

            if (($row = $queued->get($code)) !== null) {
                CatalogueContribution::query()->whereKey($row->id)->increment('seen_count', 1, ['updated_at' => $now->format('Y-m-d H:i:s')]);

                continue;
            }

            $size = PackSize::fromName($name);
            CatalogueContribution::query()->create([
                'barcode' => $code, 'name' => $name, 'size_value' => $size->value, 'size_unit' => $size->unit, 'pack_qty' => $size->pack,
                'status' => ContributionStatus::Pending, 'seen_count' => 1,
            ]);
        }
    }
}
