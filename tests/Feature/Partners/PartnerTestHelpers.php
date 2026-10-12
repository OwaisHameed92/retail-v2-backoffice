<?php

namespace Tests\Feature\Partners;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Partners\Actions\GenerateWebhookSecret;
use App\Domain\Partners\Actions\IssuePartnerKey;
use App\Domain\Partners\Actions\SavePartner;
use App\Domain\Partners\Data\ReviewFormDefaults;
use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Enums\SetupFeeMode;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for the partner onboarding API tests. Use with `uses(TenantTestHelpers::class, PartnerTestHelpers::class)`.
 */
trait PartnerTestHelpers
{
    public const WEBHOOK_URL = 'https://zivaro.test/webhooks/retail-portal';

    /** The "setup-only" plan of the doc: £1,200 setup fee per business, nothing monthly. */
    public function setupOnlyPlan(SetupFeeMode $mode = SetupFeeMode::PerBusiness, string $fee = '1200.00'): Plan
    {
        return Plan::query()->where('code', 'setup-only')->first() ?? Plan::factory()->create([
            'name' => 'Setup only',
            'code' => 'setup-only',
            'price_monthly' => '0.00',
            'price_yearly' => '0.00',
            'setup_fee' => $fee,
            'setup_fee_mode' => $mode,
            'billing_type' => PlanBillingType::SetupOnly,
            'trial_days' => 7,
            'features' => [Feature::Loyalty, Feature::Promotions],
            'sort_order' => 5,
        ]);
    }

    public function partner(string $name = 'Zivaro', ?string $webhookUrl = self::WEBHOOK_URL): Partner
    {
        return app(SavePartner::class)->handle(null, $name, $webhookUrl, true);
    }

    /** A partner with a webhook secret; returns [partner, plain API key, plain webhook secret]. */
    public function partnerWithKey(string $name = 'Zivaro', ?string $webhookUrl = self::WEBHOOK_URL): array
    {
        $partner = $this->partner($name, $webhookUrl);
        $secret = app(GenerateWebhookSecret::class)->handle($partner);
        [, $key] = app(IssuePartnerKey::class)->handle($partner, 'Live');

        return [$partner->refresh(), $key, $secret];
    }

    /**
     * The doc's example body (UK), with members replaced by `$overrides` (dot paths allowed).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function dealBody(array $overrides = []): array
    {
        $body = [
            'seller' => ['name' => 'Ali Khan', 'email' => 'ali@switchandsave.co.uk'],
            'business' => ['name' => 'Istanbul Market', 'legalName' => 'Istanbul Market Ltd', 'companyNumber' => null,
                'vatNumber' => null, 'phone' => '0121 000 0000', 'email' => 'sandra@example.co.uk'],
            'owner' => ['name' => 'Sandra Smith', 'email' => 'sandra@example.co.uk', 'phone' => '07700 900123'],
            'shops' => [['name' => 'Birmingham', 'address' => '1 High Street', 'town' => 'Birmingham', 'postcode' => 'B1 1AA', 'tills' => 2]],
            'plan' => 'setup-only',
            'setupFeePerTill' => '750.00',
            'vatApplies' => true,
            'salePrice' => '1500.00',
            'signedOn' => '2026-10-12',
            'notes' => 'Two tills, card machine from DNA',
        ];

        foreach ($overrides as $path => $value) {
            data_set($body, $path, $value);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function putDeal(string $key, array $body, string $externalId = 'zivaro-deal-42'): TestResponse
    {
        return $this->putJson('/api/partner/v1/tenant-requests/'.$externalId, $body, ['Authorization' => 'Bearer '.$key]);
    }

    public function getDeal(string $key, string $externalId = 'zivaro-deal-42'): TestResponse
    {
        return $this->getJson('/api/partner/v1/tenant-requests/'.$externalId, ['Authorization' => 'Bearer '.$key]);
    }

    public function cancelDeal(string $key, string $externalId = 'zivaro-deal-42', ?string $reason = 'Customer pulled out'): TestResponse
    {
        return $this->postJson('/api/partner/v1/tenant-requests/'.$externalId.'/cancel', ['reason' => $reason], ['Authorization' => 'Bearer '.$key]);
    }

    public function requestFor(string $externalId = 'zivaro-deal-42'): PartnerTenantRequest
    {
        return PartnerTenantRequest::query()->where('external_id', $externalId)->firstOrFail();
    }

    /**
     * The review form as the admin page pre-fills it, with `$overrides` (dot paths allowed).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function reviewForm(PartnerTenantRequest $request, array $overrides = []): array
    {
        $form = ReviewFormDefaults::for($request) + ['kind' => 'trial', 'multi_branch' => false];

        foreach ($overrides as $path => $value) {
            data_set($form, $path, $value);
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function approveAs(PartnerTenantRequest $request, AdminRole $role = AdminRole::Owner, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin($role), 'admin')->post(route('admin.partner-requests.approve', $request), $this->reviewForm($request, $overrides));
    }

    /** A Pakistan instance: COUNTRY=PK and the profile singleton rebuilt. */
    public function asPakistan(): void
    {
        config(['country.code' => 'PK']);
        app()->forgetInstance(Country::class);
    }
}
