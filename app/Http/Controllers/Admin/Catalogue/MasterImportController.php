<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Domain\Admin\Models\Admin;
use App\Domain\MasterCatalogue\Actions\StartMasterImport;
use App\Domain\MasterCatalogue\Queries\MasterImportList;
use App\Domain\MasterCatalogue\Support\MasterCsvColumns;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogue\MasterImportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Master catalogue CSV loads (/admin/catalogue/imports, `catalogue.manage`): upload, progress, template. */
class MasterImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/catalogue/imports', MasterImportList::all());
    }

    public function store(MasterImportRequest $request, StartMasterImport $start): RedirectResponse
    {
        $admin = $request->user('admin');
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $import = $start->handle($file, $request->string('source_ref')->toString(), $admin instanceof Admin ? $admin : null);

        return back()->with('success', "{$import->file_name} is loading. Progress shows below.");
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            if ($out !== false) {
                fputcsv($out, MasterCsvColumns::TEMPLATE, ',', '"', '');
                fputcsv($out, ['5449000000996', 'Coca-Cola Original Taste 500ml', 'Coca-Cola', '500', 'ml', '', 'Soft drinks', 'Soft drinks', '20', '1.85', 'none', '', 'yes'], ',', '"', '');
                fputcsv($out, ['5740600010120', 'Carlsberg Pilsner 4 x 440ml', 'Carlsberg', '440', 'ml', '4', 'Beers, wines and spirits', 'Beer and lager', '20', '5.75', 'over18', '', 'yes'], ',', '"', '');
                fclose($out);
            }
        }, 'master-catalogue-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
