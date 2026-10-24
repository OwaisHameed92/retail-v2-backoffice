<?php

use App\Domain\OnlineStore\Queries\StoreAisles;
use App\Domain\OnlineStore\Support\AddressLine;
use App\Domain\OnlineStore\Support\DisplayName;

/*
 * OS-11: how the public store shows till text: names in capitals in Title Case, one clean address line, and the
 * "no department" names that are never an aisle.
 */

test('names in capitals show in Title Case; names with lower case stay as typed', function (string $till, string $shown) {
    expect(DisplayName::of($till))->toBe($shown);
})->with([
    ['COCA COLA 500ML', 'Coca Cola 500ml'],
    ['FRUIT & VEG', 'Fruit & Veg'],
    ['BREAD AND CAKES', 'Bread and Cakes'],
    ['UK BBQ SAUCE (LARGE)', 'UK BBQ Sauce (Large)'],
    ['Walkers crisps', 'Walkers crisps'],
    ['iPhone CASE', 'iPhone CASE'],
    ['TV', 'TV'],
    [',', ','],
]);

test('an address line never repeats a town or postcode it already has', function () {
    expect(AddressLine::from('1 High St, Birmingham, B23 5GF', 'Birmingham', 'B23 5GF'))->toBe('1 High St, Birmingham, B23 5GF')
        ->and(AddressLine::from("1 High St\nErdington", 'birmingham', 'b235gf'))->toBe('1 High St, Erdington, birmingham, b235gf')
        ->and(AddressLine::from('1 High St', 'Leeds', 'LS1 1AA'))->toBe('1 High St, Leeds, LS1 1AA')
        ->and(AddressLine::from('', null, ' '))->toBeNull();
});

test('till names that mean "no department" are never an aisle', function (string $name, bool $none) {
    expect(StoreAisles::isNone($name))->toBe($none);
})->with([
    ['NO-DEPARTMENT', true], ['No Department', true], ['none', true], ['Unassigned', true], ['UNCATEGORISED', true], ['N/A', true],
    ['Default', true], ['-', true], ['No Dept', true], ['Dairy', false], ['Cat', false], ['Other', false], ['Frozen', false],
]);
