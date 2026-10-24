<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStoreBanner;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts the business's home banners in a new order (OS-8): `$ids` must be exactly its banners, each once. Audited
 * when the order changes.
 *
 *     app(OrderStoreBanners::class)->handle($company, [$second, $first]);
 */
final class OrderStoreBanners
{
    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  list<string>  $ids
     *
     * @throws ValidationException when the list is not exactly the business's banners
     */
    public function handle(Company $company, array $ids): void
    {
        $this->tenancy->runAs($company, fn () => DB::transaction(function () use ($company, $ids) {
            $banners = OnlineStoreBanner::query()->where('company_id', $company->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $current = $banners->pluck('id')->all();
            $wanted = array_values(array_unique(array_map('strval', $ids)));

            if (count($wanted) !== count($ids) || count($wanted) !== count($current) || array_diff($wanted, $current) !== []) {
                throw ValidationException::withMessages(['ids' => 'The banners changed while you were sorting them. Refresh the page.']);
            }

            if ($wanted === $current) {
                return;
            }

            $byId = $banners->keyBy('id');

            foreach ($wanted as $position => $id) {
                $byId[$id]->forceFill(['position' => $position])->save();
            }

            $this->audit->handle('online_store.banners_ordered', null, ['order' => $current], ['order' => $wanted], companyId: $company->id);
        }));
    }
}
