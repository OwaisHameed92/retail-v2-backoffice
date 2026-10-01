# Master catalogue (starter catalogue and barcode lookup)

Gap analysis must-have #7: a new owner should not key 3–8k products by hand. SSPOS keeps one platform-wide
catalogue of UK products (`master_products`, no `company_id`). Businesses copy from it; it is never sent to tills.

Code: `app/Domain/MasterCatalogue`. Admin: `/admin/catalogue` (`catalogue.manage`: owner and support).
Tenant: `/app/products/catalogue`, `/app/products/starter`, lookup on the new-product form.

## What a row holds

Barcode (EAN-13, EAN-8, UPC-A or GTIN-14, valid check digit, never an in-store `02`/`04`/`2x` number), name, brand,
size + unit (+ multipack count), department and category suggestions, VAT % suggestion, RRP, age check (the till's
`AgeRule` values), image URL, "offer in starter packs", source (`starter`, `import`, `contribution`, `admin`) with a
reference (file or supplier) and `updated_at`. A merged duplicate keeps its row with `merged_into_id`, so its barcode
still finds the product it was merged into. A UPC-A and its EAN-13 (leading 0) are treated as the same code.

## The starter set

`php artisan catalogue:starter` (or "Load starter set" on `/admin/catalogue`) loads the ~600 lines of the demo
catalogue (`DemoProducts`). Most of those barcodes are generated (valid check digits, not real products): every row
shows as "Starter set" until a licensed file replaces it. Running it again leaves rows an admin or import changed.

## Loading a bigger dataset

Use a dataset you are licensed to redistribute to customers (a wholesaler or data supplier's product file, GS1 UK
data under its licence terms, or your own). **Do not scrape retailer or other websites.**

1. Save it as CSV (UTF-8; comma, semicolon or tab). The first line names the columns; any order. Recognised headers
   (case, spaces and underscores ignored): barcode / ean / gtin / upc, name / description / product, brand,
   size / pack size (e.g. `440ml`, `4x440ml` or `440` with a unit column), unit / uom, pack qty / multipack,
   department / dept, category / sub department, vat / vat rate (percentage or `S`-style values are not read: use 20,
   5, 0), rrp / price, age / age rule (`18`, `16`, `yes`, `tobacco`, `vape`, or an `AgeRule` value),
   image / image url (https only), starter (`yes` to offer it in starter packs). Template: "Template" on the loads tab.
2. `/admin/catalogue/imports`: upload (up to 200 MB; split larger files) with a source note such as
   "Supplier X licensed file, Nov 2026". The queue worker applies it in chunks of 500 rows (`MasterImportJob`); the
   page shows progress and the rows not loaded with their reason. Run a worker: `php artisan queue:work`.
3. Rows are matched by barcode: new barcodes are added, known ones updated from the cells that are not empty, so the
   same file can be loaded again safely (nothing changes). A row with a bad barcode or no name is skipped and listed.
4. Starter packs only offer rows marked "offer in starter packs", by department name. Their departments are matched to
   the kinds of shop in `StarterPack::departments()` (Grocery, Soft drinks, Beers, wines and spirits, Tobacco and
   vaping, Confectionery and snacks, Newspapers and magazines, Household and health, Chilled, Frozen, Fresh fruit, veg
   and bakery). Use those department names in the file, or update the enum.
5. Check "Possible duplicates" on `/admin/catalogue` and merge them.

At 100k+ rows on MySQL, consider a FULLTEXT index on `name, brand` for search (the screens use `LIKE` on words today).

## Growing it from tills

When a till pushes a `ProductBarcode` the catalogue does not know, `CollectUnknownBarcodes` (called by `PushChanges`)
queues the barcode and the till's product name only. The review queue (`catalogue_contributions`) keeps barcode, name,
size and a sighting count: no business, shop, till, price or cost, and nothing in the job payload either. A business
can opt out on `/app/products/catalogue` (`companies.share_unknown_barcodes`). Admins approve (with checked details)
or reject on `/admin/catalogue/contributions`.
