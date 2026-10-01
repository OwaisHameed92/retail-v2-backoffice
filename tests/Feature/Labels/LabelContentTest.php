<?php

use App\Domain\Labels\Support\Barcode;
use App\Domain\Labels\Support\LabelStocks;
use App\Domain\Labels\Support\UnitPrice;
use App\Domain\TillData\Models\Product;

/** Gap #6: UK unit price maths, barcodes and label stock layout. */
test('unit prices: per litre, per 100ml, per 100g, per kg, rounded half up to the penny', function (string $price, string $quantity, string $unit, string $text) {
    expect(UnitPrice::calculate($price, $quantity, $unit)['text'] ?? null)->toBe($text);
})->with([
    'Coke 500ml' => ['1.25', '500', 'ml', '£2.50 per litre'],
    'milk 2.272 l' => ['1.55', '2272', 'ml', '68p per litre'],
    'miniature 50ml' => ['4.99', '50', 'ml', '£9.98 per 100ml'],
    'bread 800g' => ['1.45', '800', 'g', '18p per 100g'],
    'crisps 6 × 25g' => ['2.00', '150', 'g', '£1.33 per 100g'],
    'sugar 1kg' => ['1.15', '1000', 'g', '£1.15 per kg'],
    'potatoes 2.5kg' => ['1.99', '2500', 'g', '80p per kg'],
    'half a penny rounds up' => ['0.27', '600', 'g', '5p per 100g'],
    'just under half rounds down' => ['0.26', '600', 'g', '4p per 100g'],
    'third' => ['1.00', '300', 'g', '33p per 100g'],
]);

test('no unit price without a size or a price', function () {
    expect(UnitPrice::calculate('0.00', '500', 'ml'))->toBeNull()
        ->and(UnitPrice::calculate('1.00', '0', 'g'))->toBeNull()
        ->and(UnitPrice::parse('Mars bar'))->toBeNull()
        ->and(UnitPrice::parse('Pack of 10 Large Eggs'))->toBeNull();
});

test('the size comes from volume_ml, net_mass_kg, else the name; weighed products are priced per kg already', function () {
    $product = fn (array $attributes) => (new Product)->forceFill(['name' => 'Thing', 'is_weighed' => false, 'unit_type' => 'pcs', 'volume_ml' => null, 'net_mass_kg' => null, ...$attributes]);

    expect(UnitPrice::for($product(['volume_ml' => '330']), '0.99')['text'])->toBe('£3.00 per litre')
        ->and(UnitPrice::for($product(['net_mass_kg' => '0.25']), '2.50')['text'])->toBe('£1.00 per 100g')
        ->and(UnitPrice::for($product(['name' => 'Coca-Cola 6 x 330ml']), '3.96')['text'])->toBe('£2.00 per litre')
        ->and(UnitPrice::for($product(['name' => 'Rioja 75cl']), '7.50')['text'])->toBe('£10.00 per litre')
        ->and(UnitPrice::for($product(['name' => 'Spring Water 2L']), '0.80')['text'])->toBe('40p per litre')
        ->and(UnitPrice::for($product(['name' => 'Toastie White 800g']), '1.45')['text'])->toBe('18p per 100g')
        ->and(UnitPrice::for($product(['name' => 'Basmati 1.5kg']), '3.00')['text'])->toBe('£2.00 per kg')
        ->and(UnitPrice::for($product(['name' => 'Loose bananas', 'is_weighed' => true]), '0.79'))->toBeNull();
});

test('EAN-13 barcodes: 95 modules with guards and the check digit; a UPC-A gets a leading 0', function () {
    $ean = Barcode::for('5010044000701');
    expect(Barcode::checkDigit('501004400070'))->toBe(1)
        ->and($ean['type'])->toBe('ean13')
        ->and(strlen($ean['modules']))->toBe(95)
        ->and(substr($ean['modules'], 0, 3))->toBe('101')
        ->and(substr($ean['modules'], 45, 5))->toBe('01010')
        ->and($ean['svg'])->toStartWith('<svg')->toContain('<rect');

    // Known encoding: 4006381333931 (Wikipedia's EAN-13 example), left half under parity LGLLGG.
    expect(substr(Barcode::ean13('4006381333931'), 3, 42))->toBe('0001101'.'0100111'.'0101111'.'0111101'.'0001001'.'0110011');

    expect(Barcode::for('036000291452')['text'])->toBe('0036000291452')
        ->and(Barcode::for('5010044000702')['type'])->toBe('code128'); // wrong check digit: not an EAN
});

test('Code 128: every symbol is 11 modules (stop 13), set C for digit pairs, set B otherwise, with the mod-103 check', function () {
    foreach (Barcode::CODE128 as $value => $widths) {
        expect(array_sum(str_split($widths)))->toBe($value === 106 ? 13 : 11);
    }

    // "PJJ123C" in set B: start 104, check (104 + 48·1 + 42·2 + 42·3 + 17·4 + 18·5 + 19·6 + 35·7) % 103 = 55.
    $b = Barcode::code128('PJJ123C');
    expect(strlen($b))->toBe(11 * 9 + 13)
        ->and(substr($b, 0, 11))->toBe('11010010000') // start B
        ->and(substr($b, -24, 11))->toBe(implode('', array_map(fn ($w, $i) => str_repeat($i % 2 === 0 ? '1' : '0', (int) $w), str_split(Barcode::CODE128[55]), array_keys(str_split(Barcode::CODE128[55])))));

    $c = Barcode::for('WAR-800');
    expect($c['type'])->toBe('code128')->and(substr(Barcode::code128('12345678'), 0, 11))->toBe('11010011100') // start C
        ->and(strlen(Barcode::code128('12345678')))->toBe(11 * 6 + 13)
        ->and(Barcode::for(''))->toBeNull()->and(Barcode::for("caf\u{e9}"))->toBeNull();
});

test('label stocks land on the sheet: 3 × 8 fills 24 per A4 page, skip leaves used labels empty, rolls are one per page', function () {
    $pages = LabelStocks::layout('a4_3x8', 30);
    expect($pages)->toHaveCount(2)->and($pages[0])->toHaveCount(24)->and($pages[1])->toHaveCount(6)
        ->and($pages[0][0])->toBe(['x' => 7.2, 'y' => 12.9, 'index' => 0])
        ->and($pages[0][23])->toBe(['x' => 139.2, 'y' => 250.2, 'index' => 23]);

    $skipped = LabelStocks::layout('a4_3x8', 2, 5);
    expect($skipped[0][0])->toBe(['x' => 139.2, 'y' => 46.8, 'index' => 0]);

    expect(LabelStocks::layout('roll_50x30', 3))->toHaveCount(3)
        ->and(LabelStocks::get('nope')['key'])->toBe(LabelStocks::DEFAULT);

    foreach (LabelStocks::all() as $stock) {
        expect($stock['left'] + ($stock['cols'] - 1) * $stock['hPitch'] + $stock['width'])->toBeLessThanOrEqual($stock['pageWidth'] + 0.01)
            ->and($stock['top'] + ($stock['rows'] - 1) * $stock['vPitch'] + $stock['height'])->toBeLessThanOrEqual($stock['pageHeight'] + 0.01);
    }
});
