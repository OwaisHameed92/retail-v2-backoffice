<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStoreBanner;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes one of the business's home banners (OS-8) and its photo; the others close up in the same order. Audited.
 *
 *     app(DeleteStoreBanner::class)->handle($company, $bannerId);
 */
final class DeleteStoreBanner
{
    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @throws ValidationException when it is not one of the business's banners
     */
    public function handle(Company $company, string $bannerId): void
    {
        $banner = OnlineStoreBanner::withoutCompanyScope()->where('company_id', $company->id)->whereKey($bannerId)->first()
            ?? throw ValidationException::withMessages(['banner' => 'That banner no longer exists. Refresh the page.']);

        $this->tenancy->runAs($company, fn () => DB::transaction(function () use ($company, $banner) {
            $before = SaveStoreBanner::snapshot($banner);
            $banner->delete();

            OnlineStoreBanner::query()->where('company_id', $company->id)->where('online_store_id', $banner->online_store_id)
                ->orderBy('position')->orderBy('id')->get()
                ->each(fn (OnlineStoreBanner $b, int $i) => $b->position === $i ? null : $b->forceFill(['position' => $i])->save());

            $this->audit->handle('online_store.banner_deleted', $banner, $before, null, companyId: $company->id);
        }));

        StoreImages::delete($company, $banner->photo_path);
    }
}
