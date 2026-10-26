<?php

namespace Tests\Feature\OnlineStore;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\OnlineStore\Actions\SaveStoreShop;
use App\Domain\OnlineStore\Actions\SetStoreLive;
use App\Domain\OnlineStore\Actions\SetTakingOrders;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;

/**
 * OS-13 test helpers (with TenantTestHelpers, LicensingTestHelpers, BillingTestHelpers): an "Online store only" plan
 * (£30 a month, no tills) and a business made on it through the admin wizard, its store set up and live.
 */
trait OnlineStoreOnlyHelpers
{
    public function webOnlyPlan(string $price = '30.00', PlanBillingType $type = PlanBillingType::RecurringOnly, string $setupFee = '0.00'): Plan
    {
        return Plan::factory()->create([
            'name' => 'Online store only', 'code' => 'web-only-'.substr(md5($price.$type->value.$setupFee), 0, 6), 'billing_type' => $type,
            'setup_fee' => $setupFee, 'price_monthly' => '0.00', 'price_yearly' => '0.00', 'features' => [],
            'includes_online_store' => true, 'online_store_monthly_price' => $price, 'online_store_only' => true, 'sort_order' => 30,
        ]);
    }

    /**
     * The admin wizard's form for a business with no tills on `$plan`.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function webOnlyForm(Plan $plan, array $overrides = []): array
    {
        return [
            'name' => 'Rosa Deli', 'legal_name' => 'Rosa Deli Ltd', 'email' => 'hello@rosadeli.test', 'phone' => '0113 496 0001',
            'contact_name' => 'Rosa Bianchi', 'address' => '5 Kirkgate, Leeds', 'status' => 'active', 'trial_ends_at' => '',
            'branch_code' => 'rsd', 'branch_name' => 'Kirkgate', 'branch_nation' => 'england', 'branch_address' => '5 Kirkgate',
            'tills' => 0, 'owner_name' => 'Rosa Bianchi', 'owner_email' => 'rosa@rosadeli.test',
            'plan_id' => $plan->id, 'store_template' => 'freshMarket', ...$overrides,
        ];
    }

    /** A business made by the admin wizard on `$plan` (no tills). */
    public function webOnlyBusiness(Plan $plan, string $name = 'Rosa Deli', string $email = 'rosa@rosadeli.test'): Company
    {
        $this->actingAs($this->admin(AdminRole::Owner), 'admin')
            ->post('/admin/tenants', $this->webOnlyForm($plan, ['name' => $name, 'owner_email' => $email, 'branch_code' => strtolower(substr($name, 0, 3))]))
            ->assertRedirect()->assertSessionHasNoErrors();

        return Company::query()->where('name', $name)->firstOrFail();
    }

    public function storeOf(Company $company): OnlineStore
    {
        return OnlineStore::withoutCompanyScope()->where('company_id', $company->id)->firstOrFail();
    }

    public function shopBranch(Company $company): Branch
    {
        return Branch::withoutCompanyScope()->where('company_id', $company->id)->orderBy('created_at')->firstOrFail();
    }

    /** Its first shop collects (every day 08:00–20:00), trader details filled in, live and taking orders. */
    public function webOnlyLive(Company $company): OnlineStoreShop
    {
        $shop = app(SaveStoreShop::class)->handle($company, $this->shopBranch($company)->id, OnlineStoreFixtures::shopInput());
        OnlineStore::withoutCompanyScope()->where('company_id', $company->id)->update([
            'trading_name' => $company->name, 'legal_name' => $company->name.' Ltd', 'legal_address' => '5 Kirkgate, Leeds, LS1 6BY',
            'contact_email' => 'hello@example.test', 'order_email' => 'orders@example.test',
        ]);
        app(SetStoreLive::class)->handle($company, true);
        app(SetTakingOrders::class)->handle($company, true);

        return $shop->refresh();
    }
}
