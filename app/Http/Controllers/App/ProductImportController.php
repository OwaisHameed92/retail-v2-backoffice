<?php

namespace App\Http\Controllers\App;

use App\Domain\Catalogue\Actions\PreviewProductImport;
use App\Domain\Catalogue\Actions\QueueProductImport;
use App\Domain\Catalogue\Actions\StartProductImport;
use App\Domain\Catalogue\Import\ImportColumns;
use App\Domain\Catalogue\Models\ProductImport;
use App\Domain\Catalogue\Queries\ImportDetail;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\ProductImportRequest;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Product CSV import of the tenant portal (module 4.2, `catalogue.manage`): upload → map columns and preview → apply
 * (queued, in chunks) → result. Imports belong to the current company; another business's import is not found.
 */
class ProductImportController extends Controller
{
    public function index(CompanyWideWriteRequest $request): Response
    {
        return Inertia::render('app/products/imports/index', ['imports' => ImportDetail::recent(), 'fields' => ImportColumns::options()]);
    }

    public function store(ProductImportRequest $request, StartProductImport $start): RedirectResponse
    {
        $import = $start->handle($request->file('file'), $request->user());

        return redirect()->route('app.products.imports.show', $import->id);
    }

    public function show(CompanyWideWriteRequest $request, string $import): Response
    {
        return Inertia::render('app/products/imports/show', ImportDetail::show(ProductImport::query()->findOrFail($import)));
    }

    public function preview(ProductImportRequest $request, string $import, PreviewProductImport $preview): RedirectResponse
    {
        $preview->handle(ProductImport::query()->findOrFail($import), $request->mapping());

        return back();
    }

    public function apply(CompanyWideWriteRequest $request, string $import, QueueProductImport $queue): RedirectResponse
    {
        try {
            $queue->handle(ProductImport::query()->findOrFail($import));
        } catch (ValidationException $e) {
            return back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Import started. You can leave this page: it carries on in the background.');
    }

    /** The CSV headings the import understands, as a starting file. */
    public function template(CompanyWideWriteRequest $request): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            if ($out !== false) {
                fputcsv($out, array_map(fn (array $f) => $f['label'], ImportColumns::FIELDS), ',', '"', '');
                fputcsv($out, ['5000112637922', 'COKE-330', 'Coca-Cola Original 330ml', 'Coke 330ml', 'Coca-Cola', '', 'Drinks', 'Soft drinks', 'S', '0.99', '0.4200', 'PCS', 'none', 'yes', '12', '48', 'yes'], ',', '"', '');
                fclose($out);
            }
        }, 'products-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
