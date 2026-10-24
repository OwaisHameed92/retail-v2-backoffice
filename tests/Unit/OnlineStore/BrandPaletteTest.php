<?php

use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Support\BrandPalette;
use App\Domain\OnlineStore\Support\PackSizeLabel;

/*
 * OS-8 (audit OSA-16): the store's colours from its one brand colour, readable to WCAG AA whatever colour is chosen.
 */

test('contrast follows WCAG: black on white is 21, a colour on itself is 1', function () {
    expect(round(BrandPalette::contrast('#000000', '#FFFFFF'), 2))->toBe(21.0)
        ->and(round(BrandPalette::contrast('#0B4A34', '#0B4A34'), 2))->toBe(1.0)
        ->and(round(BrandPalette::contrast('#767676', '#FFFFFF'), 1))->toBe(4.5);
});

test('every brand colour gets readable text on it, readable brand text on white and paper, and a deep footer shade', function (string $brand, StoreTemplate $template) {
    $p = BrandPalette::for($brand, $template);
    $paper = $template === StoreTemplate::Butcher ? '#F7F3EC' : '#F4F3EE';
    $best = max(BrandPalette::contrast($brand, '#FFFFFF'), BrandPalette::contrast($brand, $template === StoreTemplate::Butcher ? '#1C1917' : '#0E1712'));

    expect(BrandPalette::contrast($p['brand'], $p['onBrand']))->toBe($best)
        ->and(BrandPalette::contrast($p['brandText'], '#FFFFFF'))->toBeGreaterThanOrEqual(4.5)
        ->and(BrandPalette::contrast($p['brandText'], $paper))->toBeGreaterThanOrEqual(4.5)
        ->and(BrandPalette::contrast($p['brandDeep'], '#FFFFFF'))->toBeGreaterThanOrEqual(7.0)
        ->and(BrandPalette::contrast($p['highlight'], $p['onHighlight']))->toBeGreaterThanOrEqual(4.5)
        ->and(BrandPalette::contrast($p['brandSoft'], '#0E1712'))->toBeGreaterThanOrEqual(4.5);
})->with([
    'forest' => ['#0B4A34', StoreTemplate::FreshMarket],
    'yellow (audit OSA-16)' => ['#F5C518', StoreTemplate::FreshMarket],
    'orange' => ['#FF8800', StoreTemplate::FreshMarket],
    'blue' => ['#1F4E9C', StoreTemplate::FreshMarket],
    'white' => ['#FFFFFF', StoreTemplate::FreshMarket],
    'black' => ['#000000', StoreTemplate::FreshMarket],
    'oxblood' => ['#8F1D1D', StoreTemplate::Butcher],
    'light pink butcher' => ['#F7C6D9', StoreTemplate::Butcher],
]);

test('a light brand colour gets ink text; a dark one white; a bad value falls back to the template colour', function () {
    expect(BrandPalette::for('#F5C518', StoreTemplate::FreshMarket)['onBrand'])->toBe('#0E1712')
        ->and(BrandPalette::for('#0B4A34', StoreTemplate::FreshMarket)['onBrand'])->toBe('#FFFFFF')
        ->and(BrandPalette::for('#0B4A34', StoreTemplate::FreshMarket)['brandText'])->toBe('#0B4A34')
        ->and(BrandPalette::for('not a colour', StoreTemplate::Butcher)['brand'])->toBe('#8F1D1D')
        ->and(BrandPalette::normalise('0b4a34'))->toBe('#0B4A34')
        ->and(BrandPalette::normalise('#12345'))->toBeNull();
});

test('Fresh Market keeps its lime with green or grey brands and takes a light shade of any other hue; the butcher keeps tan', function () {
    expect(BrandPalette::for('#0B4A34', StoreTemplate::FreshMarket)['highlight'])->toBe(BrandPalette::LIME)
        ->and(BrandPalette::for('#2A2522', StoreTemplate::FreshMarket)['highlight'])->toBe(BrandPalette::LIME)
        ->and(BrandPalette::for('#B42318', StoreTemplate::FreshMarket)['highlight'])->not->toBe(BrandPalette::LIME)
        ->and(BrandPalette::for('#1F4E9C', StoreTemplate::Butcher)['highlight'])->toBe('#F3E6D3');
});

test('pack size and unit price come from the till\'s weight or volume; weighed products have neither', function () {
    $item = fn (array $o) => new CatalogueItem(...[
        'id' => 'P', 'name' => 'X', 'description' => null, 'photoPath' => null, 'departmentId' => null, 'price' => '1.50', 'isWeighed' => false,
        'unitCode' => 'each', 'maxQtyPerSale' => null, 'stockTracked' => false, 'orderable' => true, ...$o,
    ]);

    expect(PackSizeLabel::size($item(['netMassKg' => '0.5000'])))->toBe('500 g')
        ->and(PackSizeLabel::unitPrice($item(['netMassKg' => '0.5000'])))->toBe(['amount' => '3.00', 'unit' => 'kg'])
        ->and(PackSizeLabel::size($item(['volumeMl' => '1500.0000'])))->toBe('1.5 L')
        ->and(PackSizeLabel::unitPrice($item(['volumeMl' => '750.0000'])))->toBe(['amount' => '2.00', 'unit' => 'litre'])
        ->and(PackSizeLabel::unitPrice($item(['netMassKg' => '1.0000'])))->toBeNull()
        ->and(PackSizeLabel::size($item(['isWeighed' => true, 'netMassKg' => '0.5000'])))->toBeNull()
        ->and(PackSizeLabel::size($item([])))->toBeNull();
});
