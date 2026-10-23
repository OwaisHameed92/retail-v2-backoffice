<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Hides or shows several products online at once (OS-3, the products page's bulk bar), each through
 * SetOnlineProduct (audited per product). All or nothing: every id must be one of the business's own products,
 * else nothing changes. Returns how many products changed.
 *
 *     app(SetOnlineProductsHidden::class)->handle($company, [$a, $b], hidden: true);
 */
final class SetOnlineProductsHidden
{
    public const MAX = 100;

    public function __construct(private readonly SetOnlineProduct $set) {}

    /**
     * @param  list<string>  $productIds
     *
     * @throws ValidationException
     */
    public function handle(Company $company, array $productIds, bool $hidden): int
    {
        $ids = Validator::make(['ids' => $productIds], [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['required', 'string', 'distinct', 'regex:/^[0-9A-Za-z]{26}$/'],
        ], ['ids.max' => 'Choose at most '.self::MAX.' products at a time.'])->validate()['ids'];

        $own = Product::withoutCompanyScope()->where('company_id', $company->id)->whereIn('id', $ids)->count();

        if ($own !== count($ids)) {
            throw ValidationException::withMessages(['ids' => 'Choose your own products.']);
        }

        $now = OnlineStoreProduct::withoutCompanyScope()->where('company_id', $company->id)->whereIn('product_id', $ids)->pluck('hidden', 'product_id');
        $changing = array_values(array_filter($ids, fn (string $id) => (bool) ($now[$id] ?? false) !== $hidden));

        DB::transaction(function () use ($company, $changing, $hidden): void {
            foreach ($changing as $id) {
                $this->set->handle($company, $id, ['hidden' => $hidden]);
            }
        });

        return count($changing);
    }
}
