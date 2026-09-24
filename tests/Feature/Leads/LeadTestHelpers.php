<?php

namespace Tests\Feature\Leads;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Actions\CreateLead;
use App\Domain\Leads\Data\LeadDetails;
use App\Domain\Leads\Data\TrialSetup;
use App\Domain\Leads\Data\TrialShop;
use App\Domain\Leads\Enums\BusinessType;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;

/**
 * Helpers for the module 1.6 tests. Use with `uses(TenantTestHelpers::class, LicensingTestHelpers::class, LeadTestHelpers::class)`.
 */
trait LeadTestHelpers
{
    public function leadDetails(
        string $business = 'Patel News & Booze',
        ?string $email = 'imran@patelnews.test',
        ?string $phone = '07700 900123',
        int $shops = 1,
        int $tills = 2,
        ?string $town = 'Leeds',
    ): LeadDetails {
        return new LeadDetails(
            businessName: $business,
            contactName: 'Imran Patel',
            email: $email,
            phone: $phone,
            town: $town,
            postcode: 'ls6 2ab',
            shopsCount: $shops,
            tillsCount: $tills,
            businessType: BusinessType::OffLicence,
            currentSystem: 'Paper and a cash drawer',
            message: 'Keen to start next month.',
            source: LeadSource::Website,
        );
    }

    /** A lead made through CreateLead, acting as the given admin (or as the public form when null). */
    public function makeLead(?Admin $as = null, ?LeadDetails $details = null): Lead
    {
        if ($as !== null) {
            $this->actingAs($as, 'admin');
        }

        return app(CreateLead::class)->handle($details ?? $this->leadDetails());
    }

    public function salesAdmin(string $name = 'Sam Sales'): Admin
    {
        return Admin::factory()->role(AdminRole::Sales)->create(['name' => $name]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $shops  [name, code, tills]
     */
    public function trialSetup(array $shops = [['Leeds', 'LDS', 2]], ?string $planId = null): TrialSetup
    {
        return new TrialSetup(
            array_map(fn (array $shop) => new TrialShop($shop[0], $shop[1], $shop[2]), $shops),
            $planId,
        );
    }
}
