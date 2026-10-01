<?php

namespace App\Domain\Demo\Catalogue;

/**
 * What a UK convenience store stocks (`demo:seed`), as compact data: departments, their categories and the ranges in
 * each. A range line is "Name|size:price,size:price" (price in pence inc VAT); every size becomes one product
 * ("Name size"). Costs come from the category's margin. DemoProducts turns this into ~600 products.
 */
final class DemoRange
{
    /** Department key => [name, colour, VAT code of most of its goods]. */
    public const DEPARTMENTS = [
        'grocery' => ['Grocery', '#C2410C', 'Z'],
        'drinks' => ['Soft drinks', '#0284C7', 'S'],
        'alcohol' => ['Beers, wines and spirits', '#7C3AED', 'S'],
        'tobacco' => ['Tobacco and vaping', '#475569', 'S'],
        'confectionery' => ['Confectionery and snacks', '#DB2777', 'S'],
        'newspapers' => ['Newspapers and magazines', '#0F766E', 'Z'],
        'household' => ['Household and health', '#4F46E5', 'S'],
        'chilled' => ['Chilled', '#0891B2', 'Z'],
        'frozen' => ['Frozen', '#2563EB', 'Z'],
        'fresh' => ['Fresh fruit, veg and bakery', '#16A34A', 'Z'],
    ];

    /**
     * Category key => [department, name, VAT code, margin % of the net price, case size, sales weight, when
     * (morning|evening|any), age rule, flags (alcohol, tobacco, expiry, hfss), range lines].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: string, 7: string, 8: list<string>, 9: list<string>}>
     */
    public const CATEGORIES = [
        'bread' => ['grocery', 'Bread and rolls', 'Z', 24, 12, 6, 'morning', 'none', ['expiry'], [
            'Warburtons Medium White|400g:115,800g:155', 'Kingsmill 50/50|800g:150', 'Hovis Best of Both|750g:155',
            'Warburtons Crumpets 6 Pack|:110', 'Warburtons Sandwich Thins 6 Pack|:155', 'Kingsmill White Rolls 6 Pack|:120',
            'Warburtons Seeded Batch|800g:185', 'Hovis Granary|800g:175', 'Roberts Small White|400g:110',
        ]],
        'tins' => ['grocery', 'Tins and jars', 'Z', 26, 12, 3, 'any', 'none', [], [
            'Heinz Cream of Tomato Soup|400g:150', 'Heinz Spaghetti Hoops|400g:110', 'Branston Baked Beans|410g:95',
            'John West Tuna Chunks in Brine|145g:199', 'Napolina Chopped Tomatoes|400g:85', 'Princes Corned Beef|340g:399',
            'Green Giant Sweetcorn|198g:110', 'Batchelors Mushy Peas|300g:75', 'Heinz Ravioli|400g:140', 'Ambrosia Custard|400g:150',
            'Fray Bentos Steak and Kidney Pie|425g:375', 'Baxters Vegetable Soup|400g:175', 'Del Monte Peach Slices|415g:150',
            'Princes Hot Dogs in Brine|400g:160', 'Heinz Mushroom Soup|400g:150', 'Prince\'s Pilchards in Tomato Sauce|400g:175',
            'Heinz Baked Beanz|200g:85,4 x 415g:450', 'Heinz Chicken Soup|400g:150', 'Campbell\'s Condensed Mushroom|295g:120',
            'Branston Beans|4 x 410g:350', 'Princes Tuna Chunks in Sunflower Oil|145g:175',
        ]],
        'cereal' => ['grocery', 'Breakfast cereals', 'Z', 22, 10, 2, 'morning', 'none', ['hfss'], [
            'Kellogg\'s Corn Flakes|250g:199,500g:325', 'Weetabix 12 Pack|:250,24 Pack:425', 'Kellogg\'s Coco Pops|350g:325',
            'Quaker Oat So Simple Original 10 Sachets|:299', 'Kellogg\'s Rice Krispies|340g:299', 'Shreddies|415g:299',
            'Ready Brek Original|450g:275', 'Kellogg\'s Crunchy Nut|375g:349',
        ]],
        'hotdrinks' => ['grocery', 'Tea and coffee', 'Z', 25, 6, 2, 'morning', 'none', [], [
            'Yorkshire Tea 80 Tea Bags|:375,160 Tea Bags:650', 'Tetley 80 Tea Bags|:299', 'Typhoo 80 Tea Bags|:275',
            'Kenco Smooth Instant Coffee|100g:450', 'Nescafe Gold Blend|100g:599', 'Douwe Egberts Pure Gold|95g:499',
            'Cadbury Drinking Chocolate|250g:299', 'Horlicks Original|500g:450', 'Twinings English Breakfast 50 Tea Bags|:325',
        ]],
        'dry' => ['grocery', 'Pasta, rice and noodles', 'Z', 27, 12, 2, 'any', 'none', [], [
            'Napolina Fusilli|500g:125', 'Napolina Spaghetti|500g:125', 'Tilda Basmati Rice|500g:275,1kg:450',
            'Uncle Ben\'s Microwave Rice Golden Vegetable|250g:199', 'Pot Noodle Bombay Bad Boy|90g:130', 'Pot Noodle Beef and Tomato|90g:130',
            'Super Noodles Chicken|90g:85', 'Batchelors Pasta n Sauce Chicken and Mushroom|99g:120', 'Mug Shot Pasta Cheese|68g:110',
            'Indomie Mi Goreng|80g:60', 'Maggi 3 Minute Noodles Chicken|59g:60',
        ]],
        'cooking' => ['grocery', 'Cooking and baking', 'Z', 26, 12, 2, 'any', 'none', [], [
            'Tate and Lyle Granulated Sugar|1kg:150', 'McDougalls Self Raising Flour|1kg:150', 'KTC Vegetable Oil|1L:250',
            'Bisto Gravy Granules|170g:199', 'Oxo Beef Stock Cubes 12 Pack|:150', 'Saxa Table Salt|750g:110', 'Colman\'s English Mustard|100g:175',
            'Dolmio Bolognese Sauce|500g:250', 'Homepride Curry Sauce|500g:175', 'Patak\'s Tikka Masala Paste|283g:275',
            'Schwartz Black Pepper|33g:199', 'Flora Light|500g:250', 'Hellmann\'s Real Mayonnaise|400g:299',
            'Rajah Garam Masala|100g:150', 'East End Chilli Powder|100g:125', 'Heera Chapatti Flour|1.5kg:250', 'KTC Pure Ghee|500g:550',
            'Tilda Sona Masoori Rice|2kg:599', 'TRS Red Lentils|500g:150',
        ]],
        'sauces' => ['grocery', 'Sauces and spreads', 'Z', 28, 12, 2, 'any', 'none', [], [
            'Heinz Tomato Ketchup|460g:250,910g:399', 'HP Brown Sauce|450g:275', 'Branston Original Pickle|360g:225',
            'Nutella Hazelnut Spread|350g:375', 'Marmite Yeast Extract|250g:450', 'Robertson\'s Golden Shred Marmalade|454g:225',
            'Hartley\'s Best Strawberry Jam|340g:225', 'Sun-Pat Smooth Peanut Butter|400g:299', 'Lea and Perrins Worcestershire Sauce|150ml:175',
        ]],
        'softdrinks' => ['drinks', 'Soft drinks', 'S', 40, 24, 8, 'any', 'none', ['hfss'], [
            'Coca-Cola Original Taste|330ml can:99,1.25L:235,2L:325', 'Diet Coke|330ml can:99,500ml:175,2L:299',
            'Coca-Cola Zero Sugar|330ml can:99,500ml:175,2L:299', 'Pepsi Max|330ml can:85,500ml:150,2L:249',
            'Fanta Orange|330ml can:99,500ml:175,2L:275', 'Sprite|330ml can:99,500ml:175', 'Dr Pepper|330ml can:99,500ml:175',
            'Irn-Bru|330ml can:85,500ml:150,2L:249', 'Tango Orange|330ml can:85,2L:225', '7UP Free|330ml can:85,2L:225',
            'Rubicon Mango|330ml can:95', 'Vimto Fizzy|330ml can:85,500ml:150', 'Ribena Blackcurrant|500ml:175',
            'Robinsons Fruit Creations|500ml:150', 'Oasis Summer Fruits|500ml:175', 'Old Jamaica Ginger Beer|330ml can:110',
            'Lucozade Zero Pink Lemonade|500ml:150', 'Pepsi Original|330ml can:85,2L:249', 'Fanta Fruit Twist|500ml:175',
            'Coca-Cola Cherry|330ml can:99,500ml:185', 'Barr Cream Soda|2L:150', 'Schweppes Lemonade|2L:175', 'Schweppes Indian Tonic|1L:199', 'Appletiser|275ml:150',
        ]],
        'energy' => ['drinks', 'Energy and sports drinks', 'S', 38, 24, 6, 'morning', 'energyDrink16', [], [
            'Red Bull Sugar Free|250ml:165,473ml:250', 'Monster Energy Green|500ml:175', 'Monster Ultra White|500ml:175',
            'Monster Pipeline Punch|500ml:175', 'Relentless Origin|500ml:150', 'Rockstar Original|500ml:125', 'Prime Hydration Ice Pop|500ml:225',
            'Lucozade Sport Orange|500ml:150', 'Lucozade Energy Orange|380ml:150', 'Boost Energy Original|250ml can:75', 'Emerge Energy|250ml can:59',
            'Monster Mango Loco|500ml:175', 'Red Bull Tropical|250ml:165', 'Monster Ultra Paradise|500ml:175', 'Gatorade Cool Blue|500ml:150',
            'Lucozade Energy Pink Lemonade|500ml:175',
        ]],
        'water' => ['drinks', 'Water and juice', 'S', 42, 24, 3, 'any', 'none', [], [
            'Highland Spring Still Water|500ml:99,1.5L:125', 'Volvic Touch of Fruit Strawberry|500ml:150', 'Buxton Sparkling Water|500ml:110',
            'Tropicana Orange Juice|850ml:325', 'Innocent Orange Juice|330ml:225', 'Capri-Sun Orange|200ml:65', 'J2O Orange and Passion Fruit|275ml:175',
            'Ribena Light|1L:275', 'Robinsons Orange Squash|1L:225', 'Evian Natural Mineral Water|1.5L:150',
        ]],
        'beer' => ['alcohol', 'Beer and lager', 'S', 20, 6, 4, 'evening', 'over18', ['alcohol'], [
            'Fosters Lager|4 x 440ml:575,10 x 440ml:1200', 'Budweiser|4 x 440ml:575,12 x 300ml:1350', 'Peroni Nastro Azzurro|4 x 330ml:675',
            'Corona Extra|4 x 330ml:650', 'San Miguel|4 x 440ml:650', 'Kronenbourg 1664|4 x 440ml:625', 'Guinness Draught|4 x 440ml:675',
            'Coors|4 x 440ml:599', 'Heineken|4 x 440ml:625', 'Tennent\'s Lager|4 x 440ml:575', 'John Smith\'s Extra Smooth|4 x 440ml:575',
            'Kingfisher Premium Lager|4 x 330ml:599', 'Cobra Premium Beer|4 x 330ml:625', 'Desperados|3 x 330ml:625', 'Brewdog Punk IPA|4 x 330ml:725', 'Carling|4 x 440ml:550,18 x 440ml:1700', 'Stella Artois|10 x 440ml:1300', 'Fosters Lager|18 x 440ml:1700',
        ]],
        'cider' => ['alcohol', 'Cider', 'S', 22, 6, 2, 'evening', 'over18', ['alcohol'], [
            'Strongbow Original|4 x 440ml:575', 'Magners Original|4 x 440ml:650', 'Thatchers Gold|4 x 440ml:650',
            'Kopparberg Strawberry and Lime|500ml:299', 'Old Mout Kiwi and Lime|500ml:275', 'Bulmers Original|500ml:250',
            'Frosty Jack\'s|2.5L:500', 'Rekorderlig Strawberry Lime|500ml:299',
        ]],
        'wine' => ['alcohol', 'Wine', 'S', 26, 6, 3, 'evening', 'over18', ['alcohol'], [
            'Echo Falls Rose|75cl:700', 'Echo Falls White Zinfandel|75cl:700', 'Hardys Stamp Shiraz Cabernet|75cl:750', 'Hardys VR Chardonnay|75cl:650',
            'Blossom Hill Rose|75cl:750', 'Yellow Tail Shiraz|75cl:850', 'Casillero del Diablo Merlot|75cl:950', 'Jacob\'s Creek Chardonnay|75cl:850',
            'Barefoot Pinot Grigio|75cl:750', 'Isla Negra Sauvignon Blanc|75cl:799', 'Freixenet Cordon Negro Cava|75cl:999', 'Echo Falls Fruit Fusion Summer Berries|75cl:700',
            'Lambrini Original|75cl:399', 'Hardys Stamp Pinot Grigio|75cl:650', 'Echo Falls Merlot|1.5L:1200', 'Prosecco DOC Spumante|75cl:899',
        ]],
        'spirits' => ['alcohol', 'Spirits', 'S', 18, 6, 2, 'evening', 'over18', ['alcohol'], [
            'Smirnoff Red Label Vodka|35cl:1150', 'Glen\'s Vodka|70cl:1700', 'Gordon\'s London Dry Gin|70cl:2000', 'Bell\'s Original Whisky|70cl:1900',
            'Famous Grouse|70cl:2000', 'Jack Daniel\'s Old No.7|70cl:2700', 'Captain Morgan Spiced Gold|70cl:2200', 'Bacardi Carta Blanca|70cl:2000',
            'Jagermeister|70cl:2200', 'Baileys Original Irish Cream|70cl:1800', 'Courvoisier VS Cognac|35cl:2000', 'Malibu Coconut|70cl:1700',
            'Glen\'s Vodka|35cl:950', 'Bell\'s Original Whisky|35cl:1100', 'Smirnoff Red Label Vodka|1L:2600', 'Bell\'s Original Whisky|1L:2600',
            'Glen\'s Vodka|1L:2300', 'Disaronno Amaretto|50cl:1700',
        ]],
        'cigarettes' => ['tobacco', 'Cigarettes', 'S', 6, 10, 7, 'any', 'tobaccoGenerational', ['tobacco'], [
            'Marlboro Gold|20:1650', 'Benson and Hedges Gold|20:1625', 'Lambert and Butler Original Silver|20:1525', 'Mayfair Original Blue|20:1475',
            'Sterling Dual Capsule|20:1450', 'JPS Players Real Blue|20:1475', 'Silk Cut Purple|20:1625', 'Richmond Original Blue|20:1450',
            'Rothmans Blue|20:1499', 'Embassy Signature|20:1575', 'Superkings Black|20:1575',
        ]],
        'rolling' => ['tobacco', 'Rolling tobacco', 'S', 6, 5, 3, 'any', 'tobaccoGenerational', ['tobacco'], [
            'Amber Leaf Original|30g:1999,50g:3299', 'Golden Virginia Original|30g:2199', 'Gold Leaf Original|30g:1899', 'Sterling Dark Blue|30g:1899',
            'Cutters Choice Original|30g:1799',
        ]],
        'smokers' => ['tobacco', 'Papers, filters and lighters', 'S', 45, 50, 2, 'any', 'over18', [], [
            'Rizla Green King Size Papers|:85', 'Rizla Silver Papers|:75', 'Swan Extra Slim Filter Tips|:110', 'Rizla Ultra Slim Filters|:110',
            'Clipper Lighter Assorted|:150', 'Bic Maxi Lighter|:175', 'Zippo Lighter Fluid|125ml:450', 'Swan Vestas Matches|:60',
        ]],
        'vapes' => ['tobacco', 'Vapes and e-liquids', 'S', 42, 10, 3, 'evening', 'nicotine', [], [
            'Elf Bar 600 Watermelon|:599', 'Elf Bar 600 Blueberry Sour Raspberry|:599', 'Lost Mary BM600 Triple Mango|:599', 'Lost Mary BM600 Blueberry Ice|:599',
            'SKE Crystal Bar Rainbow|:599', 'Elux Legend 3500 Pod Kit Cherry|:1299', 'IVG Bar Plus Lemon Lime|:599', 'Elf Bar Elfliq Blue Razz Lemonade|10ml:399',
            'Hayati Pro Max Gummy Bear|:599', 'Elf Bar Elfa Pro Pod Kit|:899',
        ]],
        'chocolate' => ['confectionery', 'Chocolate', 'S', 34, 48, 6, 'any', 'none', ['hfss'], [
            'Cadbury Wispa|36g:95', 'Cadbury Twirl|43g:110', 'Cadbury Crunchie|40g:95', 'Cadbury Double Decker|54.5g:110', 'Cadbury Freddo|18g:30',
            'Snickers|48g:110', 'Twix|50g:110', 'Bounty|57g:110', 'Galaxy Smooth Milk|42g:110', 'Maltesers|37g:110', 'KitKat 4 Finger|41.5g:95',
            'Aero Milk|36g:95', 'Yorkie Milk|46g:110', 'Toffee Crisp|38g:95', 'Lion Bar|42g:95', 'Cadbury Dairy Milk|110g:225,180g:325',
            'Galaxy Smooth Milk Sharing|110g:225', 'Maltesers Pouch|102g:250', 'Kinder Bueno|43g:110', 'Ferrero Rocher 3 Pack|:150',
            'Cadbury Fruit and Nut|110g:225', 'Milky Way|21.5g:65', 'Reese\'s Peanut Butter Cups|42g:110', 'Cadbury Boost|48.5g:110',
            'Cadbury Caramel|45g:110', 'Cadbury Flake|32g:95', 'Cadbury Picnic|48.4g:110', 'KitKat Chunky|40g:110', 'Mars Duo|78.8g:150',
            'Snickers Duo|83.4g:150', 'Twix Xtra|75g:150', 'Cadbury Wispa Gold|48g:110', 'Galaxy Caramel|48g:110', 'Cadbury Buttons Bag|95g:199', 'Milkybar Buttons|30g:95',
        ]],
        'sweets' => ['confectionery', 'Sweets and mints', 'S', 38, 24, 3, 'any', 'none', ['hfss'], [
            'Haribo Starmix|140g:125', 'Haribo Tangfastics|140g:125', 'Rowntree\'s Fruit Pastilles|52.5g:85', 'Skittles Fruits|45g:85',
            'Starburst Original|45g:85', 'Maynards Bassetts Wine Gums|130g:125', 'Polo Original|34g:70', 'Extra Peppermint Gum|:75',
            'Trebor Extra Strong Mints|:70', 'Smints Peppermint|:150', 'Fox\'s Glacier Mints|130g:125', 'Swizzels Love Hearts|:30',
            'Chupa Chups Lollipop|:30', 'Haribo Giant Strawbs|180g:150',
        ]],
        'crisps' => ['confectionery', 'Crisps and snacks', 'S', 36, 32, 6, 'any', 'none', ['hfss'], [
            'Walkers Cheese and Onion|32.5g:110', 'Walkers Salt and Vinegar|32.5g:110', 'Walkers Prawn Cocktail|32.5g:110',
            'Walkers Ready Salted 6 Pack|:225', 'Walkers Sensations Thai Sweet Chilli|65g:150', 'Pringles Original|165g:325',
            'Pringles Sour Cream and Onion|165g:325', 'Doritos Tangy Cheese|150g:225', 'Hula Hoops Original|34g:85', 'Quavers Cheese|20g:75',
            'Monster Munch Pickled Onion|40g:85', 'Wotsits Really Cheesy|36g:85', 'McCoy\'s Ridge Cut Flame Grilled Steak|45g:110',
            'Kettle Chips Sea Salt|130g:250', 'Skips Prawn Cocktail|13.1g:50', 'Discos Salt and Vinegar|30g:65', 'KP Salted Peanuts|65g:125',
            'Mini Cheddars Original|50g:110', 'Pom-Bears Original|19g:65', 'Pork Farms Pork Scratchings|40g:150',
            'Walkers Max Paprika|50g:150', 'Doritos Cool Original|150g:225', 'Walkers Cheese and Onion 6 Pack|:225', 'Tyrrells Lightly Sea Salted|150g:299',
            'Bacon Fries|24g:65', 'Popchips Barbeque|85g:199', 'Twiglets Original|105g:199', 'KP Dry Roasted Peanuts|65g:125',
        ]],
        'biscuits' => ['confectionery', 'Biscuits and cakes', 'Z', 30, 12, 2, 'any', 'none', ['hfss'], [
            'McVitie\'s Digestives|400g:150', 'McVitie\'s Rich Tea|300g:125', 'Fox\'s Crunch Creams|230g:125', 'Jaffa Cakes 10 Pack|:150',
            'Custard Creams|400g:85', 'Bourbon Creams|400g:85', 'Mr Kipling Bakewell Slices 6 Pack|:175', 'Soreen Malt Loaf|190g:150',
            'Jacob\'s Cream Crackers|200g:135', 'Ritz Original|200g:175', 'Cadbury Mini Rolls 5 Pack|:175', 'McVitie\'s Gold Bar Multipack|:175',
        ]],
        'papers' => ['newspapers', 'Daily newspapers', 'Z', 23, 1, 5, 'morning', 'none', [], [
            'The Sun|:80', 'Daily Mail|:125', 'Daily Mirror|:150', 'Daily Star|:70', 'Daily Express|:120', 'The Times|:250', 'The Guardian|:300',
            'The Daily Telegraph|:300', 'i Newspaper|:150', 'Racing Post|:350', 'Express and Star|:110', 'Birmingham Mail|:120',
        ]],
        'sundays' => ['newspapers', 'Sunday papers', 'Z', 23, 1, 2, 'morning', 'none', [], [
            'The Sun on Sunday|:150', 'The Mail on Sunday|:250', 'Sunday Mirror|:220', 'The Sunday Times|:350', 'The Observer|:350',
            'Sunday Express|:200', 'Sunday People|:200',
        ]],
        'magazines' => ['newspapers', 'Magazines', 'Z', 25, 1, 1, 'any', 'none', [], [
            'Take a Break|:150', 'Woman\'s Weekly|:225', 'Radio Times|:350', 'TV Choice|:125', 'Puzzler|:299', 'Bella|:150',
            'Hello!|:250', 'Kerrang!|:399', 'The Beano|:375', 'Private Eye|:299', 'What Car?|:599', 'Hello Kitty and Friends|:599', 'Viz|:499', 'Match of the Day|:350', 'National Geographic|:699',
            'Top Gear|:599', 'Woman|:150', 'Chat|:150',
        ]],
        'cleaning' => ['household', 'Cleaning', 'S', 30, 12, 1, 'any', 'none', [], [
            'Fairy Platinum Dishwasher Tablets 22 Pack|:850', 'Persil Bio Washing Liquid 24 Washes|:799', 'Lenor Fabric Conditioner|925ml:399',
            'Domestos Original Thick Bleach|750ml:150', 'Flash All Purpose Cleaner|1L:225', 'Mr Muscle Window Cleaner|750ml:299',
            'Fairy Lemon Washing Up Liquid|383ml:150', 'Zoflora Linen Fresh|120ml:150', 'Harpic Power Plus Toilet Cleaner|750ml:250',
            'Ariel All in 1 Pods 15 Pack|:699', 'Cif Cream Lemon|500ml:199', 'Spontex Sponge Scourers 2 Pack|:125',
            'Comfort Pure Fabric Conditioner|1.16L:350', 'Fairy Non Bio Pods 18 Pack|:699', 'Dettol Antibacterial Surface Wipes 30|:250',
            'Method Kitchen Spray|490ml:299', 'Finish Dishwasher Tablets 30|:999',
        ]],
        'toiletries' => ['household', 'Toiletries', 'S', 32, 12, 1, 'any', 'none', [], [
            'Colgate Total Toothpaste|75ml:350', 'Head and Shoulders Classic Clean Shampoo|250ml:450', 'Dove Original Beauty Bar 2 Pack|:199',
            'Lynx Africa Body Spray|150ml:399', 'Sure Women Invisible Deodorant|150ml:350', 'Gillette Blue II Razors 5 Pack|:299',
            'Imperial Leather Shower Gel|250ml:150', 'Carex Original Handwash|250ml:150', 'Pampers Baby Dry Size 4 22 Pack|:799',
            'Always Ultra Normal 14 Pack|:250', 'Johnson\'s Baby Wipes 56 Pack|:150', 'Vaseline Original Petroleum Jelly|50ml:175',
            'Colgate Max Fresh|75ml:250', 'Lynx Africa Shower Gel|225ml:350', 'Nivea Men Deodorant|150ml:350', 'Radox Feel Refreshed Shower Gel|225ml:175',
            'Oral-B Toothbrush|:199', 'TRESemme Shampoo|500ml:450',
        ]],
        'paper' => ['household', 'Paper, foil and bags', 'S', 28, 6, 1, 'any', 'none', [], [
            'Cushelle Toilet Tissue 9 Roll|:550', 'Plenty Kitchen Roll 2 Roll|:350', 'Kleenex Balsam Tissues|:199', 'Bacofoil Kitchen Foil|10m:250',
            'Clingfilm|30m:199', 'Bin Bags 20 Pack|:150', 'Freezer Bags 50 Pack|:125',
        ]],
        'health' => ['household', 'Health and medicines', 'S', 40, 12, 1, 'any', 'paracetamol16', [], [
            'Ibuprofen Tablets 200mg 16|:50', 'Nurofen Express 200mg 16|:375', 'Lemsip Max Cold and Flu Lemon 10 Sachets|:550',
            'Gaviscon Double Action Tablets 24|:550', 'Rennie Peppermint 24|:350', 'Strepsils Honey and Lemon 16|:450', 'Beechams All in One 16|:450',
            'Elastoplast Fabric Plasters 20|:299', 'Benylin Chesty Cough|150ml:675', 'Piriteze Allergy Tablets 7|:450',
        ]],
        'general' => ['household', 'Batteries, bulbs and general', 'S', 35, 6, 1, 'any', 'none', [], [
            'Duracell Plus AAA 4 Pack|:550', 'Energizer Max AA 8 Pack|:899', 'Duracell 9V Battery|:450', 'Philips LED Bulb B22|:399',
            'Bostik Glue Stick|:175', 'Scotch Magic Tape|:225', 'Pet Food Felix As Good As It Looks 12 Pack|:599', 'Pedigree Chum Original|400g:110',
            'Zip Firelighters 24|:225', 'Barbecue Charcoal|5kg:599', 'Phone Top Up Voucher Assorted|:1000', 'Greetings Card Assorted|:199',
        ]],
        'milk' => ['chilled', 'Milk and cream', 'Z', 18, 12, 9, 'morning', 'none', ['expiry'], [
            'Whole Milk|1 Pint:95,2 Pints:155,4 Pints:229', 'Skimmed Milk|2 Pints:155,4 Pints:229', 'Cravendale Semi Skimmed|1L:199,2L:325',
            'Arla Lactofree Semi Skimmed|1L:199', 'Elmlea Double|250ml:150', 'Single Cream|300ml:125', 'Frijj Chocolate Milkshake|471ml:175',
            'Yazoo Strawberry|400ml:150', 'Alpro Soya Original|1L:200', 'Oatly Oat Barista|1L:225', 'Semi Skimmed Milk|1 Pint:95,1L:125',
        ]],
        'dairy' => ['chilled', 'Cheese, butter and yoghurts', 'Z', 24, 12, 3, 'any', 'none', ['expiry'], [
            'Anchor Butter|250g:299', 'Lurpak Slightly Salted Butter|250g:325', 'Clover Spread|500g:299', 'Pilgrims Choice Mature Cheddar|350g:425',
            'Dairylea Triangles 8 Pack|:225', 'Babybel Mini Original 6 Pack|:299', 'Philadelphia Original|180g:225', 'Muller Corner Strawberry|:110',
            'Activia Strawberry 4 Pack|:225', 'Petits Filous 6 Pack|:225', 'Muller Rice Original|:110', 'Cathedral City Mature Cheddar|200g:299',
            'Mozzarella Ball|125g:110', 'Muller Light Vanilla|:110', 'Yeo Valley Natural Yoghurt|500g:199',
        ]],
        'meat' => ['chilled', 'Cooked meats and ready meals', 'Z', 26, 6, 3, 'any', 'none', ['expiry'], [
            'Smoked Back Bacon|300g:350', 'Richmond Thick Pork Sausages 8 Pack|:325', 'Wall\'s Classic Pork Sausages 8 Pack|:299',
            'Cooked Ham Slices|125g:199', 'Peperami Original|25g:110', 'Ginsters Cornish Pasty|:250', 'Ginsters Steak Slice|:250',
            'Pork Pie|140g:150', 'Scotch Egg|113g:125', 'Fresh Pizza Margherita|:399', 'Halal Chicken Breast Fillets|500g:450',
            'Cooked Chicken Tikka Pieces|150g:299',
        ]],
        'food2go' => ['chilled', 'Sandwiches and food to go', 'Z', 40, 6, 5, 'any', 'none', ['expiry'], [
            'Egg Mayonnaise Sandwich|:275', 'Ham and Cheese Sandwich|:299', 'Tuna and Sweetcorn Sandwich|:299', 'BLT Sandwich|:325',
            'Chicken Caesar Wrap|:350', 'Cheese and Onion Sandwich|:275', 'Prawn Mayonnaise Sandwich|:350', 'Pasta Salad Chicken and Bacon|:325',
            'Sausage Roll|:150', 'Chicken Tikka Wrap|:350',
        ]],
        'eggs' => ['chilled', 'Eggs', 'Z', 22, 12, 2, 'morning', 'none', ['expiry'], [
            'Large Free Range Eggs|6 Pack:275,12 Pack:450', 'Medium Free Range Eggs|12 Pack:399', 'Mixed Weight Eggs|15 Pack:350',
        ]],
        'frozenmeals' => ['frozen', 'Frozen meals and meat', 'Z', 26, 8, 2, 'evening', 'none', ['expiry'], [
            'Birds Eye Chicken Dippers|220g:275', 'Birds Eye Fish Fingers 10 Pack|:299', 'McCain Home Chips|1.5kg:350', 'McCain Oven Chips|1.1kg:299',
            'Chicago Town Takeaway Pepperoni Pizza|:399', 'Goodfella\'s Stonebaked Margherita|:299', 'Aunt Bessie\'s Yorkshire Puddings 12 Pack|:199',
            'Birds Eye Original Beef Burgers 4 Pack|:299', 'Youngs Chip Shop Battered Cod|2 Pack:350', 'Iceland Chicken Nuggets|500g:250',
            'Kiev Garlic Chicken 2 Pack|:250', 'Halal Lamb Mince|500g:450',
            'Pukka Steak Pie|:299', 'Chicago Town Deep Dish 2 Pack|:350', 'Birds Eye Potato Waffles 10 Pack|:299', 'McCain Crinkle Cut Chips|1.1kg:299',
            'Linda McCartney Sausages 6 Pack|:350', 'Vegetable Samosas 12 Pack|:299',
        ]],
        'icecream' => ['frozen', 'Ice cream and ice', 'S', 32, 12, 2, 'evening', 'none', ['expiry', 'hfss'], [
            'Ben and Jerry\'s Cookie Dough|465ml:599', 'Magnum Classic 4 Pack|:399', 'Cornetto Classico 4 Pack|:350', 'Carte D\'Or Vanilla|900ml:350',
            'Haagen-Dazs Salted Caramel|460ml:599', 'Twister Lolly 4 Pack|:299', 'Party Ice|2kg:175', 'Mars Ice Cream Bar 4 Pack|:399',
        ]],
        'frozenveg' => ['frozen', 'Frozen vegetables', 'Z', 24, 12, 1, 'any', 'none', ['expiry'], [
            'Birds Eye Garden Peas|800g:250', 'Frozen Mixed Vegetables|1kg:150', 'Frozen Sweetcorn|500g:125', 'Frozen Broccoli Florets|750g:150',
            'Aunt Bessie\'s Roast Potatoes|700g:275',
        ]],
        'fruit' => ['fresh', 'Fruit', 'Z', 30, 10, 3, 'any', 'none', ['expiry'], [
            'Bananas|Each:25,5 Pack:115', 'Royal Gala Apples|6 Pack:199', 'Easy Peeler Oranges|600g:175', 'Seedless Grapes|500g:225',
            'Strawberries|400g:250', 'Lemons|3 Pack:99', 'Pears|4 Pack:175', 'Blueberries|150g:199', 'Mango|Each:125', 'Avocado|Each:99',
        ]],
        'veg' => ['fresh', 'Vegetables and salad', 'Z', 30, 10, 3, 'any', 'none', ['expiry'], [
            'White Potatoes|2.5kg:175', 'Brown Onions|1kg:110', 'Carrots|1kg:85', 'Tomatoes|6 Pack:110', 'Cucumber|Each:85',
            'Iceberg Lettuce|Each:85', 'Red Peppers|3 Pack:175', 'Garlic Bulbs|4 Pack:99', 'Mushrooms|300g:125', 'Fresh Coriander|30g:75',
            'Green Chillies|50g:75', 'Spring Onions|100g:70', 'Ginger Root|150g:75', 'Okra|200g:125',
        ]],
        'bakery' => ['fresh', 'Fresh bakery', 'Z', 45, 6, 3, 'morning', 'none', ['expiry'], [
            'Croissants 4 Pack|:175', 'Pain au Chocolat 4 Pack|:199', 'Jam Doughnuts 4 Pack|:150', 'Iced Buns 4 Pack|:150',
            'Fresh Baguette|:99', 'Hot Cross Buns 4 Pack|:150', 'Chocolate Muffins 2 Pack|:199', 'Naan Breads 4 Pack|:150',
        ]],
    ];

    /**
     * The 35 products `demo:sales` always sold (DemoCatalogue::PRODUCTS keys) and the category they belong to; their ids
     * stay the same so earlier demo lines keep pointing at a real product.
     */
    public const LEGACY_CATEGORY = [
        'bread' => 'bread', 'wholemeal' => 'bread', 'milk2' => 'milk', 'milk4' => 'milk', 'eggs' => 'eggs', 'beans' => 'tins',
        'teabags' => 'hotdrinks', 'coffee' => 'hotdrinks', 'butter' => 'dairy', 'cheese' => 'dairy', 'sandwich' => 'food2go',
        'potnoodle' => 'dry', 'paper' => 'papers', 'coke' => 'softdrinks', 'walkers' => 'crisps', 'dairymilk' => 'chocolate',
        'mars' => 'chocolate', 'redbull' => 'energy', 'lucozade' => 'energy', 'water' => 'water', 'carlsberg' => 'beer',
        'stella' => 'beer', 'merlot' => 'wine', 'vodka' => 'spirits', 'cider' => 'cider', 'cigs' => 'cigarettes', 'rizla' => 'smokers',
        'vape' => 'vapes', 'toilet' => 'paper', 'fairy' => 'cleaning', 'battery' => 'general', 'paracetamol' => 'health',
        'patches' => 'health', 'lozenges' => 'health', 'coal' => 'general',
    ];
}
