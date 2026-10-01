<?php

namespace App\Domain\MasterCatalogue\Actions;

use App\Domain\Admin\Models\Admin;
use App\Domain\MasterCatalogue\Enums\ContributionStatus;
use App\Domain\MasterCatalogue\Enums\MasterSource;
use App\Domain\MasterCatalogue\Models\CatalogueContribution;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An admin's decision on a barcode collected from tills: approve (with the details the admin checked: name, brand,
 * size, department, VAT, RRP, age check) adds it to the master catalogue as source "From tills"; reject keeps it out
 * (later sightings only raise its count). Only a pending contribution can be reviewed.
 */
final class ReviewContribution
{
    public const SOURCE_REF = 'Till contributions';

    public function __construct(private readonly SaveMasterProduct $save, private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>|null  $details  null = reject
     */
    public function handle(CatalogueContribution $contribution, ?array $details, ?Admin $admin): ?MasterProduct
    {
        if ($contribution->status !== ContributionStatus::Pending) {
            throw ValidationException::withMessages(['contribution' => 'This barcode has already been reviewed.']);
        }

        return DB::transaction(function () use ($contribution, $details, $admin) {
            $product = null;

            if ($details !== null) {
                [$product] = $this->save->handle(null, [...Arr::except($details, ['barcode']), 'barcode' => $contribution->barcode], MasterSource::Contribution, self::SOURCE_REF);
            }

            $contribution->forceFill([
                'status' => $product === null ? ContributionStatus::Rejected : ContributionStatus::Approved,
                'master_product_id' => $product?->id,
                'reviewed_by' => $admin?->id,
                'reviewed_at' => CarbonImmutable::now('UTC'),
            ])->save();

            $this->audit->handle($product === null ? 'catalogue_contribution.rejected' : 'catalogue_contribution.approved', $contribution, null, null, [
                'barcode' => $contribution->barcode, 'name' => $product->name ?? $contribution->name,
            ], $admin);

            return $product;
        });
    }
}
