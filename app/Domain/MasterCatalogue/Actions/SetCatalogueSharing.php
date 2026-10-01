<?php

namespace App\Domain\MasterCatalogue\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;

/**
 * A business opts in or out of sharing its tills' unknown barcodes (barcode, name and size only) with the master
 * catalogue's review queue (CollectUnknownBarcodes). On by default; audited.
 */
final class SetCatalogueSharing
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Company $company, bool $share): void
    {
        if ($company->share_unknown_barcodes === $share) {
            return;
        }

        $before = $company->share_unknown_barcodes;
        $company->forceFill(['share_unknown_barcodes' => $share])->save();

        $this->audit->handle('catalogue.sharing_changed', $company, ['share_unknown_barcodes' => $before], ['share_unknown_barcodes' => $share], [], null, $company->id);
    }
}
