<?php

namespace Tests\Feature\Pricing;

use App\Domain\Catalogue\Actions\SaveProduct;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Product;
use Illuminate\Support\Arr;
use Tests\Feature\Catalogue\CatalogueFixtures;

/**
 * Module 4.3 test data: a business's catalogue (CatalogueFixtures) and a product saved as the portal would.
 */
final class PricingFixtures
{
    /**
     * @param  array<string, string>  $ids  from CatalogueFixtures::seed
     * @param  array<string, mixed>  $overrides
     */
    public static function product(Company $company, array $ids, array $overrides = []): Product
    {
        $form = CatalogueFixtures::form($ids, ['barcodes' => [], ...$overrides]);

        return app(CurrentCompany::class)->runAs($company, fn () => app(SaveProduct::class)
            ->handle(null, Arr::except($form, ['barcodes', 'units']), $form['barcodes'], $form['units'])->product);
    }

    /**
     * A complete offer form submission.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function offer(string $productId, array $overrides = []): array
    {
        return [
            'name' => 'Toastie 10% off', 'type' => 'percentOff', 'scope' => 'product', 'target_id' => $productId, 'percent' => '10',
            'amount_off' => null, 'deal_price' => null, 'buy_quantity' => null, 'get_quantity' => null, 'min_quantity' => '1', 'priority' => '0',
            'allow_stack' => false, 'is_exclusive' => false, 'max_redemptions_per_sale' => null, 'max_redemptions_total' => null,
            'requires_coupon' => false, 'coupon_code' => null, 'branch_id' => null, 'is_hfss_safe' => false, 'effective_from' => '2026-10-01',
            'effective_to' => null, 'time_from' => null, 'time_to' => null, 'is_active' => true, 'items' => [],
            ...$overrides,
        ];
    }
}
