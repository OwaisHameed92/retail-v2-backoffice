<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Domain\Admin\Models\Admin;
use App\Domain\MasterCatalogue\Actions\ReviewContribution;
use App\Domain\MasterCatalogue\Models\CatalogueContribution;
use App\Domain\MasterCatalogue\Queries\ContributionList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogue\MasterProductRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The review queue of barcodes collected from tills (/admin/catalogue/contributions, `catalogue.manage`): approve with
 * checked details (it joins the master catalogue) or reject.
 */
class ContributionController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/catalogue/contributions', ContributionList::for($request));
    }

    public function approve(MasterProductRequest $request, CatalogueContribution $contribution, ReviewContribution $review): RedirectResponse
    {
        $product = $review->handle($contribution, $request->details(), $this->admin($request));

        return back()->with('success', ($product->name ?? $contribution->name).' added to the catalogue.');
    }

    public function reject(Request $request, CatalogueContribution $contribution, ReviewContribution $review): RedirectResponse
    {
        $review->handle($contribution, null, $this->admin($request));

        return back()->with('success', "Barcode {$contribution->barcode} rejected.");
    }

    private function admin(Request $request): ?Admin
    {
        $admin = $request->user('admin');

        return $admin instanceof Admin ? $admin : null;
    }
}
