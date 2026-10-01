<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Demo\Catalogue\DemoRange;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Reporting\Demo\DemoCatalogue;
use Carbon\CarbonImmutable;

/**
 * The catalogue a convenience store's till holds: VAT rates (20/5/0), the unit, ten departments with their
 * categories, ~600 products with EAN-13 barcodes (an outer-case barcode on canned and bottled drinks), the carrier
 * bag, and a few shop price overrides. Pushed from the main shop's till; ids match the ones demo sales use.
 */
final class CatalogueBuilder
{
    public const VAT_NAMES = ['S' => 'Standard', 'R' => 'Reduced', 'Z' => 'Zero rated'];

    public function handle(DemoBusiness $b, DemoPush $push): void
    {
        $main = $b->main();
        $at = $b->at($b->history + 30, 8);

        foreach (DemoCatalogue::VAT as $code => $percentage) {
            $push->add($main, 'VatRate', $b->id("vat|{$code}"), [
                'name' => self::VAT_NAMES[$code], 'code' => $code, 'percentage' => $percentage, 'treatment' => 'apply',
                'effectiveFrom' => '2011-01-04', 'effectiveTo' => null, 'isDefault' => $code === 'S', 'isZeroValued' => $code === 'Z',
            ], $at);
        }

        $push->add($main, 'Unit', $b->id('unit|each'), [
            'code' => 'each', 'name' => 'Each', 'plural' => 'Each', 'symbol' => 'ea', 'kind' => 'each', 'decimalsAllowed' => false,
            'decimalPlaces' => 0, 'isActive' => true, 'isSystem' => true, 'position' => 1,
        ], $at);

        $position = 0;

        foreach (DemoRange::DEPARTMENTS as $key => [$name, $colour, $vat]) {
            $push->add($main, 'Department', $b->id("department|{$key}"), [
                'name' => $name, 'position' => ++$position, 'colourHex' => $colour, 'isActive' => true, 'isVisibleOnTill' => true,
                'showInReport' => true, 'defaultVatRateId' => $b->id("vat|{$vat}"),
            ], $at);
        }

        $position = 0;

        foreach (DemoRange::CATEGORIES as $key => $c) {
            $push->add($main, 'Category', $b->id("category|{$key}"), [
                'departmentId' => $b->id("department|{$c[0]}"), 'parentCategoryId' => null, 'name' => $c[1], 'position' => ++$position,
                'colourHex' => DemoRange::DEPARTMENTS[$c[0]][1], 'isActive' => true, 'isVisibleOnTill' => true, 'ageRuleDefault' => $c[7],
                'defaultVatRateId' => $b->id("vat|{$c[2]}"), 'negativeStockMode' => in_array($c[0], ['newspapers', 'fresh'], true) ? 'allow' : 'warn',
            ], $at);
        }

        $tile = 0;

        foreach (DemoProducts::all() as $key => $p) {
            $this->product($b, $push, $p, ++$tile, $at);
        }

        $this->bag($b, $push, $at);
        $this->shopPrices($b, $push);
    }

    /**
     * @param  array<string, mixed>  $p  a DemoProducts entry
     */
    private function product(DemoBusiness $b, DemoPush $push, array $p, int $tile, CarbonImmutable $at): void
    {
        $id = $b->id("product|{$p['key']}");
        $case = (int) $p['case'];
        $name = (string) $p['name'];
        $isAge = $p['ageRule'] !== 'none';

        $push->add($b->main(), 'Product', $id, [
            'name' => $name, 'shortName' => mb_substr($name, 0, 24), 'sku' => strtoupper(substr(md5((string) $p['key']), 0, 8)),
            'brand' => strtok($name, ' '), 'description' => null, 'departmentId' => $b->id("department|{$p['department']}"),
            'categoryId' => $b->id("category|{$p['category']}"), 'unitType' => 'pcs', 'unitCode' => 'each',
            'vatRateId' => $b->id("vat|{$p['vat']}"), 'costPrice' => $p['cost'] / 100, 'sellPrice' => $p['price'] / 100,
            'pmpPrice' => str_contains($name, 'Pack') || $p['department'] === 'confectionery' ? $p['price'] / 100 : null,
            'trackStock' => $p['department'] !== 'newspapers', 'minStockQty' => max(2, intdiv($case, 2)), 'maxStockQty' => $case * 3,
            'reorderQty' => $case, 'ageRule' => $p['ageRule'], 'maxQtyPerSale' => $p['category'] === 'health' ? 2 : null,
            'maxQtyReason' => $p['category'] === 'health' ? 'Pain relief: at most 2 packs per sale' : null,
            'tracksExpiryDates' => $p['expiry'], 'isHfss' => $p['hfss'], 'isDepositItem' => false, 'isAlcohol' => $p['alcohol'],
            'isTobacco' => $p['tobacco'], 'isLottery' => false, 'isKnife' => false, 'isBanned' => false,
            'vapeDutyApplies' => $p['category'] === 'vapes', 'tileColourHex' => DemoRange::DEPARTMENTS[$p['department']][1],
            'tilePosition' => $tile, 'isActive' => true, 'isVariantParent' => false, 'isVariant' => false, 'isWeighed' => false,
            'isOpenPrice' => false, 'isAgeRestricted' => $isAge, 'receiptName' => mb_substr($name, 0, 32),
        ], $at);

        $push->add($b->main(), 'ProductBarcode', $b->id("barcode|{$p['key']}"), [
            'productId' => $id, 'barcode' => $p['barcode'], 'packQty' => 1, 'isPrimary' => true, 'unitCode' => 'each', 'source' => 'gs1',
        ], $at);

        // Drinks are scanned in by the case at the cash and carry: the outer has its own barcode.
        if (in_array($p['category'], ['softdrinks', 'energy', 'water', 'beer'], true) && $case > 1) {
            $outer = DemoProducts::ean13('50'.substr(str_pad((string) abs(crc32("{$p['key']}|outer")), 10, '0', STR_PAD_LEFT), 0, 10));
            $push->add($b->main(), 'ProductBarcode', $b->id("barcode|{$p['key']}|outer"), [
                'productId' => $id, 'barcode' => $outer, 'packQty' => $case, 'isPrimary' => false, 'unitCode' => 'each', 'source' => 'supplier',
                'notes' => "Outer of {$case}",
            ], $at);
        }
    }

    private function bag(DemoBusiness $b, DemoPush $push, CarbonImmutable $at): void
    {
        $bag = DemoCatalogue::BAG;
        $id = $b->id('product|bag');
        $push->add($b->main(), 'Product', $id, [
            'name' => $bag['name'], 'shortName' => $bag['name'], 'sku' => 'BAG', 'departmentId' => $b->id('department|household'),
            'categoryId' => $b->id('category|general'), 'unitType' => 'pcs', 'unitCode' => 'each', 'vatRateId' => $b->id('vat|S'),
            'costPrice' => $bag['cost'] / 100, 'sellPrice' => $bag['price'] / 100, 'trackStock' => false, 'ageRule' => 'none',
            'tileColourHex' => '#4F46E5', 'tilePosition' => 0, 'isActive' => true, 'receiptName' => $bag['name'],
        ], $at);
        $push->add($b->main(), 'ProductBarcode', $b->id('barcode|bag'), [
            'productId' => $id, 'barcode' => $bag['barcode'], 'packQty' => 1, 'isPrimary' => true, 'unitCode' => 'each', 'source' => 'internal',
        ], $at);
    }

    /** A handful of shop-only prices: the second and third shops charge a little more for some lines. */
    private function shopPrices(DemoBusiness $b, DemoPush $push): void
    {
        foreach (array_slice($b->shops, 1) as $i => ['shop' => $shop]) {
            $rng = $b->rng("prices|{$shop->branchId}");
            $keys = array_keys(DemoProducts::all());

            for ($n = 0; $n < 12; $n++) {
                $p = DemoProducts::get($keys[$rng->getInt(0, count($keys) - 1)]);
                $from = $b->at(20 + $n, 7);
                $push->add($shop, 'BranchPrice', $shop->id("price|{$p['key']}"), [
                    'productId' => $b->id("product|{$p['key']}"), 'productUnitId' => null, 'price' => ($p['price'] + 10 * (1 + $i)) / 100,
                    'validFromUtc' => DemoBusiness::iso($from), 'validToUtc' => null, 'branchId' => $shop->branchId,
                ], $from);
            }
        }
    }
}
