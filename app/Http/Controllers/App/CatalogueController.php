<?php

namespace App\Http\Controllers\App;

use App\Domain\MasterCatalogue\Actions\AddFromCatalogue;
use App\Domain\MasterCatalogue\Actions\AddStarterPack;
use App\Domain\MasterCatalogue\Actions\SetCatalogueSharing;
use App\Domain\MasterCatalogue\Enums\StarterPack;
use App\Domain\MasterCatalogue\Queries\BarcodeLookup;
use App\Domain\MasterCatalogue\Queries\CatalogueSearch;
use App\Domain\MasterCatalogue\Queries\StarterPackPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Catalogue\AddFromCatalogueRequest;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The SSPOS starter catalogue in the tenant portal (`catalogue.manage`, not a one-shop user): "Add from catalogue"
 * (search the master catalogue, pick many, price, map departments), the onboarding starter pack, barcode lookup for
 * the product form, and the business's choice to share unknown barcodes. Products are created through SaveProduct,
 * so every till gets them at its next sync.
 */
class CatalogueController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(CompanyWideWriteRequest $request): Response
    {
        return Inertia::render('app/products/catalogue', CatalogueSearch::for($request, $this->tenancy));
    }

    public function add(AddFromCatalogueRequest $request, AddFromCatalogue $add): RedirectResponse
    {
        return back()->with('success', self::message($add->handle($request->items(), $request->priceRule(), $request->mapping())));
    }

    public function starter(CompanyWideWriteRequest $request): Response
    {
        return Inertia::render('app/products/starter', StarterPackPage::for($request->string('pack')->toString(), $this->tenancy));
    }

    public function addStarter(AddFromCatalogueRequest $request, AddStarterPack $add): RedirectResponse
    {
        $result = $add->handle(StarterPack::from((string) $request->validated('pack')), $request->included(), $request->priceRule(), $request->mapping());

        return redirect()->route('app.products.index')->with('success', self::message($result));
    }

    public function lookup(CompanyWideWriteRequest $request): JsonResponse
    {
        return response()->json(BarcodeLookup::find(mb_substr($request->string('barcode')->toString(), 0, 20)));
    }

    public function sharing(CompanyWideWriteRequest $request, SetCatalogueSharing $set): RedirectResponse
    {
        $set->handle($this->tenancy->require(), $request->boolean('share'));

        return back()->with('success', $request->boolean('share') ? 'Thank you: new barcodes your tills sell help grow the catalogue.' : 'Your tills\' barcodes are no longer shared.');
    }

    /**
     * @param  array{created: int, existing: list<string>, failed: list<array{barcode: string, name: string, message: string}>}  $result
     */
    private static function message(array $result): string
    {
        $parts = [$result['created'] === 1 ? '1 product added' : "{$result['created']} products added"];

        if ($result['existing'] !== []) {
            $parts[] = count($result['existing']).' skipped (you already have the barcode)';
        }

        if ($result['failed'] !== []) {
            $first = $result['failed'][0];
            $parts[] = count($result['failed'])." not added ({$first['name']}: {$first['message']})";
        }

        return implode(', ', $parts).'. Your tills get them at their next sync.';
    }
}
