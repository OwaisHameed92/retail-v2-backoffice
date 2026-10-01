<?php

namespace App\Domain\Reporting\Demo;

/**
 * What a demo UK convenience shop sells (`demo:sales`): prices inc VAT and costs ex VAT in pence, VAT band
 * (S 20%, R 5%, Z 0%), a popularity weight and when it sells best. Multi-buys give the promotion discounts.
 */
final class DemoCatalogue
{
    /** Code => percentage. */
    public const VAT = ['S' => 20, 'R' => 5, 'Z' => 0];

    /** Opening hours (local, first hour .. last hour) Monday–Saturday and Sunday. */
    public const HOURS = ['week' => [7, 21], 'sunday' => [9, 17]];

    /** Relative footfall per local hour. */
    public const HOUR_WEIGHTS = [
        7 => 5, 8 => 8, 9 => 6, 10 => 5, 11 => 6, 12 => 9, 13 => 8, 14 => 5, 15 => 6, 16 => 8, 17 => 10, 18 => 9,
        19 => 7, 20 => 5, 21 => 3,
    ];

    /** Trading level per ISO weekday (1 = Monday). */
    public const WEEKDAY = [1 => 0.94, 2 => 0.92, 3 => 0.96, 4 => 1.0, 5 => 1.12, 6 => 1.18, 7 => 0.86];

    /** Multi-buy: product key => [qty, price for that qty in pence, name]. */
    public const MULTIBUYS = [
        'coke' => [2, 300, 'Coca-Cola 2 for £3'],
        'walkers' => [2, 180, 'Walkers 2 for £1.80'],
        'redbull' => [2, 300, 'Red Bull 2 for £3'],
        'carlsberg' => [2, 1000, 'Carlsberg 2 for £10'],
    ];

    /** The carrier bag line (isBagCharge). */
    public const BAG = ['key' => 'bag', 'name' => 'Carrier bag', 'barcode' => '5000000000104', 'price' => 10, 'cost' => 3, 'vat' => 'S'];

    /**
     * key => [name, barcode, price, cost, vat, weight, when (morning|evening|any), age restricted]
     *
     * @var array<string, array{0: string, 1: string, 2: int, 3: int, 4: string, 5: int, 6: string, 7: bool}>
     */
    public const PRODUCTS = [
        'bread' => ['Warburtons Toastie White Bread 800g', '5010044000701', 145, 98, 'Z', 9, 'morning', false],
        'wholemeal' => ['Hovis Soft Wholemeal 800g', '5010003000163', 150, 102, 'Z', 5, 'morning', false],
        'milk2' => ['Semi Skimmed Milk 2 Pints', '5000128700010', 155, 105, 'Z', 12, 'morning', false],
        'milk4' => ['Semi Skimmed Milk 4 Pints', '5000128700027', 229, 160, 'Z', 7, 'morning', false],
        'eggs' => ['Free Range Eggs 6 Pack', '5000295142201', 229, 150, 'Z', 4, 'any', false],
        'beans' => ['Heinz Baked Beans 415g', '5000157024671', 140, 92, 'Z', 5, 'any', false],
        'teabags' => ['PG Tips 80 Tea Bags', '8722700055525', 260, 180, 'Z', 3, 'morning', false],
        'coffee' => ['Nescafe Original 100g', '7613035315839', 425, 300, 'Z', 2, 'any', false],
        'butter' => ['Lurpak Spreadable 500g', '5740900402694', 450, 320, 'Z', 3, 'any', false],
        'cheese' => ['Cathedral City Mature Cheddar 350g', '5000295013372', 400, 280, 'Z', 3, 'any', false],
        'sandwich' => ['Chicken and Bacon Sandwich', '5000119123452', 325, 190, 'Z', 6, 'any', false],
        'potnoodle' => ['Pot Noodle Chicken and Mushroom', '8712100868696', 130, 85, 'Z', 3, 'any', false],
        'paper' => ['Yorkshire Evening Post', '9771353770019', 120, 90, 'Z', 5, 'morning', false],
        'coke' => ['Coca-Cola Original Taste 500ml', '5449000000996', 185, 78, 'S', 14, 'any', false],
        'walkers' => ['Walkers Ready Salted Crisps 32.5g', '5000328109927', 110, 55, 'S', 10, 'any', false],
        'dairymilk' => ['Cadbury Dairy Milk 45g', '7622210449283', 125, 68, 'S', 9, 'any', false],
        'mars' => ['Mars Bar 51g', '5000159407236', 95, 50, 'S', 6, 'any', false],
        'redbull' => ['Red Bull Energy Drink 250ml', '9002490100070', 165, 95, 'S', 8, 'morning', false],
        'lucozade' => ['Lucozade Energy Original 380ml', '5000108097672', 150, 85, 'S', 5, 'any', false],
        'water' => ['Evian Natural Mineral Water 500ml', '3068320113784', 110, 50, 'S', 4, 'any', false],
        'carlsberg' => ['Carlsberg Pilsner 4 x 440ml', '5740600010120', 575, 400, 'S', 6, 'evening', true],
        'stella' => ['Stella Artois 4 x 440ml', '5410228141266', 650, 450, 'S', 4, 'evening', true],
        'merlot' => ['Echo Falls Merlot 75cl', '5010186010507', 700, 420, 'S', 4, 'evening', true],
        'vodka' => ['Smirnoff Red Label Vodka 70cl', '5410316952705', 1800, 1250, 'S', 2, 'evening', true],
        'cider' => ['Strongbow Dark Fruit 4 x 440ml', '5010102238084', 575, 390, 'S', 3, 'evening', true],
        'cigs' => ['Marlboro Gold 20', '5000277001427', 1650, 1480, 'S', 7, 'any', true],
        'rizla' => ['Rizla Blue Regular Papers', '5010133100305', 60, 30, 'S', 2, 'any', true],
        'vape' => ['Elf Bar 600 Blue Razz', '6975720600014', 599, 330, 'S', 3, 'evening', true],
        'toilet' => ['Andrex Classic Clean Toilet Tissue 4 Roll', '5029053038896', 350, 240, 'S', 2, 'any', false],
        'fairy' => ['Fairy Original Washing Up Liquid 383ml', '8001090621061', 150, 95, 'S', 2, 'any', false],
        'battery' => ['Duracell Plus AA 4 Pack', '5000394076961', 550, 330, 'S', 1, 'any', false],
        'paracetamol' => ['Paracetamol Tablets 500mg 16', '5000158101586', 45, 20, 'S', 2, 'any', false],
        'patches' => ['Nicorette Invisi Patch 15mg 7 Patches', '5000347073214', 1350, 950, 'R', 1, 'any', false],
        'lozenges' => ['NiQuitin Mint Lozenges 4mg 20', '5000347070817', 1200, 850, 'R', 1, 'any', false],
        'coal' => ['Smokeless Fuel 10kg Bag', '5012345000017', 1200, 820, 'R', 1, 'evening', false],
    ];

    /** Number of lines in a basket => weight. */
    public const BASKET_LINES = [1 => 40, 2 => 27, 3 => 15, 4 => 9, 5 => 5, 6 => 4];
}
