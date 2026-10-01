<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Domain\MasterCatalogue\Actions\LoadStarterSet;
use App\Domain\MasterCatalogue\Actions\MergeMasterProducts;
use App\Domain\MasterCatalogue\Actions\SaveMasterProduct;
use App\Domain\MasterCatalogue\Enums\MasterSource;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Queries\MasterCatalogueList;
use App\Domain\MasterCatalogue\Queries\MasterProductForm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogue\MasterProductRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform-wide master catalogue (/admin/catalogue, `catalogue.manage`: owner and support): list and search,
 * add and edit, merge duplicates, load the starter set. Businesses copy from it; it is never tenant data.
 */
class MasterCatalogueController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/catalogue/index', MasterCatalogueList::for($request));
    }

    public function create(): Response
    {
        return Inertia::render('admin/catalogue/form', MasterProductForm::for(null));
    }

    public function store(MasterProductRequest $request, SaveMasterProduct $save): RedirectResponse
    {
        [$product] = $save->handle(null, $request->details(), MasterSource::Admin, 'Added on /admin/catalogue');

        return redirect()->route('admin.catalogue.edit', $product->id)->with('success', "{$product->name} added to the catalogue.");
    }

    public function edit(MasterProduct $product): Response
    {
        return Inertia::render('admin/catalogue/form', MasterProductForm::for($product));
    }

    public function update(MasterProductRequest $request, MasterProduct $product, SaveMasterProduct $save): RedirectResponse
    {
        [, $outcome] = $save->handle($product, $request->details(), MasterSource::Admin, 'Edited on /admin/catalogue');

        return back()->with('success', $outcome === 'unchanged' ? 'Nothing to save: no changes.' : 'Catalogue product saved.');
    }

    /** Merges this product into the one to keep (`keep`: its id). */
    public function merge(Request $request, MasterProduct $product, MergeMasterProducts $merge): RedirectResponse
    {
        $data = $request->validate(['keep' => ['required', 'string', 'size:26']]);
        $keep = $merge->handle($product, MasterProduct::query()->findOrFail($data['keep']));

        return redirect()->route('admin.catalogue.edit', $keep->id)->with('success', "Merged: barcode {$product->barcode} now finds {$keep->name}.");
    }

    public function starter(LoadStarterSet $load): RedirectResponse
    {
        $counts = $load->handle();

        return back()->with('success', "Starter set loaded: {$counts['created']} added, {$counts['updated']} updated, {$counts['unchanged']} unchanged.");
    }
}
