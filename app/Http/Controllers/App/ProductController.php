<?php

namespace App\Http\Controllers\App;

use App\Domain\Catalogue\Actions\ArchiveProduct;
use App\Domain\Catalogue\Actions\RestoreProduct;
use App\Domain\Catalogue\Actions\SaveProduct;
use App\Domain\Catalogue\Queries\CatalogueOptions;
use App\Domain\Catalogue\Queries\ProductForm;
use App\Domain\Catalogue\Queries\ProductList;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\TillData\Models\Product;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\SaveProductRequest;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Products of the tenant portal (module 4.2): list (`catalogue.view`), create / edit / archive / restore
 * (`catalogue.manage`: owner and manager). Products are hub-owned: every save reaches every till at its next pull.
 * Everything runs in the current company's scope: another business's product is simply not found.
 */
class ProductController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        return Inertia::render('app/products/index', [
            ...ProductList::for($request),
            'options' => CatalogueOptions::all(),
            'canManage' => $this->canManage(),
        ]);
    }

    public function create(CompanyWideWriteRequest $request): Response
    {
        return Inertia::render('app/products/form', [...ProductForm::for(null), 'canManage' => true]);
    }

    public function store(SaveProductRequest $request, SaveProduct $save): RedirectResponse
    {
        $saved = $save->handle(null, $request->productAttributes(), $request->barcodes(), $request->units());

        return redirect()->route('app.products.show', $saved->product->id)
            ->with('success', "{$saved->product->name} added. Your tills get it at their next sync.");
    }

    public function show(string $product): Response
    {
        return Inertia::render('app/products/form', [
            ...ProductForm::for(Product::query()->findOrFail($product)),
            'canManage' => $this->canManage(),
        ]);
    }

    public function update(SaveProductRequest $request, string $product, SaveProduct $save): RedirectResponse
    {
        $saved = $save->handle(Product::query()->findOrFail($product), $request->productAttributes(), $request->barcodes(), $request->units());

        return back()->with('success', $saved->changed === [] ? 'Nothing to save: no changes.' : 'Product saved. Your tills get the change at their next sync.');
    }

    public function archive(CompanyWideWriteRequest $request, string $product, ArchiveProduct $archive): RedirectResponse
    {
        $model = $archive->handle(Product::query()->findOrFail($product));

        return back()->with('success', "{$model->name} archived. Tills stop offering it at their next sync.");
    }

    public function restore(CompanyWideWriteRequest $request, string $product, RestoreProduct $restore): RedirectResponse
    {
        $model = $restore->handle(Product::query()->findOrFail($product));

        return back()->with('success', "{$model->name} is active again.");
    }

    /** Catalogue changes reach every shop: a one-shop user may look only (module 4.3 owner decision). */
    private function canManage(): bool
    {
        return $this->tenancy->can(Ability::CatalogueManage) && $this->tenancy->restrictedBranchId() === null;
    }
}
