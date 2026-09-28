<?php

namespace Tests\Feature\Plans;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Plans\Data\PlanInput;
use App\Domain\Plans\Enums\Feature;

function planAdmin(AdminRole $role = AdminRole::Owner): Admin
{
    return Admin::factory()->role($role)->create();
}

/**
 * A valid create/update request body.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function planPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Standard',
        'code' => 'standard',
        'description' => 'Everything a convenience store needs.',
        'price_monthly' => '30.00',
        'price_yearly' => '300.00',
        'trial_days' => 7,
        'trial_grace_days' => 3,
        'grace_days' => 7,
        'features' => ['stockControl', 'cashOffice'],
        'is_active' => true,
        'is_public' => true,
        'sort_order' => 10,
    ], $overrides);
}

/**
 * @param  list<Feature|string>  $features
 */
function planInput(
    string $name = 'Standard',
    string $code = 'standard',
    string $monthly = '30.00',
    string $yearly = '300.00',
    array $features = [Feature::StockControl],
    bool $isActive = true,
    bool $isPublic = true,
): PlanInput {
    return new PlanInput(
        name: $name,
        code: $code,
        description: 'A plan.',
        priceMonthly: $monthly,
        priceYearly: $yearly,
        features: $features,
        isActive: $isActive,
        isPublic: $isPublic,
        sortOrder: 10,
    );
}
