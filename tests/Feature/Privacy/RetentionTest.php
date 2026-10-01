<?php

use App\Domain\Customers\Actions\SaveCustomer;
use App\Domain\Privacy\Actions\SavePrivacySettings;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Privacy\Models\PrivacySettings;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Customers\CustomerFixtures as F;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 7.7: data retention. The setting (owner only, sent to the tills), the daily run (dry run unless the owner
 * turned on automatic anonymising), "anonymise now", and one business's setting never touching another's customers.
 */

beforeEach(function () {
    $this->travelTo('2026-10-24 12:00:00');
    $this->withoutVite();
    [$this->company] = TillFixtures::tenant();
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $make = function (Company $company, string $name, string $updatedAt): Customer {
        $customer = app(SaveCustomer::class)->handle($company, null, ['name' => $name, 'email' => strtolower(strtok($name, ' ')).'@example.co.uk']);
        DB::table('customers')->where('id', $customer->id)->update(['updated_at' => $updatedAt, 'created_at' => $updatedAt]);

        return $customer;
    };
    $this->old = $make($this->company, 'Olive Old', '2023-01-01 00:00:00');
    $this->recentSale = $make($this->company, 'Sam Shopper', '2023-01-01 00:00:00');
    DB::table('sales')->insert(['id' => '01K5T0Q8C4000000000000SAL9', 'company_id' => $this->company->id, 'branch_id' => TillFixtures::LEEDS, 'customer_id' => $this->recentSale->id, 'completed_at' => '2026-06-01 10:00:00']);
    $this->owing = $make($this->company, 'Owen Owing', '2023-01-01 00:00:00');
    F::ledger($this->company->id, TillFixtures::LEEDS, $this->owing->id, 'charge', '5.00', 0, '2023-01-01 00:00:00');
    $this->fresh = $make($this->company, 'Fran Fresh', '2026-09-01 00:00:00');

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherOld = $make($this->other, 'Otto Other', '2020-01-01 00:00:00');
});

test('owners set the retention period; it is audited and sent to the tills; out-of-range values are refused', function () {
    $this->actingAs($this->owner)->put('/app/privacy/settings', ['retention_months' => 3])->assertSessionHasErrors('retention_months');
    $this->actingAs($this->owner)->put('/app/privacy/settings', ['retention_months' => 24, 'auto_anonymise' => false])->assertSessionHasNoErrors();

    $settings = PrivacySettings::withoutCompanyScope()->sole();
    expect($settings->company_id)->toBe($this->company->id)->and($settings->retention_months)->toBe(24)->and($settings->due_count)->toBe(2)
        ->and(DB::table('till_settings')->where('company_id', $this->company->id)->where('setting_key', SavePrivacySettings::TILL_KEY)->whereNull('deleted_at')->value('value'))->toBe('24')
        ->and(DB::table('audit_logs')->where('action', 'privacy.settings_updated')->count())->toBe(1);

    $this->actingAs($this->owner)->get('/app/privacy')->assertInertia(fn ($page) => $page
        ->where('settings.dueCount', 2)->has('due', 2)->where('due.0.settled', fn ($v) => is_bool($v)));
});

test('the daily run only counts by default, anonymises when the owner turned it on, skips unsettled accounts and leaves other businesses alone', function () {
    app(SavePrivacySettings::class)->handle($this->company, 24, false, null);

    $this->artisan('privacy:retention', ['--apply' => true])->assertSuccessful();
    expect(Customer::withoutCompanyScope()->whereNotNull('anonymised_at')->count())->toBe(0)
        ->and(PrivacySettings::withoutCompanyScope()->sole()->due_count)->toBe(2);

    app(SavePrivacySettings::class)->handle($this->company, 24, true, null);
    $this->artisan('privacy:retention')->assertSuccessful();
    expect(Customer::withoutCompanyScope()->whereNotNull('anonymised_at')->count())->toBe(0);

    $this->artisan('privacy:retention', ['--apply' => true])->assertSuccessful();

    expect(Customer::withoutCompanyScope()->find($this->old->id)->anonymised_at)->not->toBeNull()
        ->and(Customer::withoutCompanyScope()->find($this->owing->id)->anonymised_at)->toBeNull()
        ->and(Customer::withoutCompanyScope()->find($this->recentSale->id)->anonymised_at)->toBeNull()
        ->and(Customer::withoutCompanyScope()->find($this->fresh->id)->anonymised_at)->toBeNull()
        ->and(Customer::withoutCompanyScope()->find($this->otherOld->id)->anonymised_at)->toBeNull()
        ->and(DataRequest::withoutCompanyScope()->sole()->source)->toBe('retention')
        ->and(PrivacySettings::withoutCompanyScope()->sole()->due_count)->toBe(1);
});

test('"anonymise them now" anonymises this business\'s due customers only', function () {
    app(SavePrivacySettings::class)->handle($this->company, 24, false, null);

    $this->actingAs($this->owner)->post('/app/privacy/retention/apply')->assertRedirect()->assertSessionHas('success');

    expect(Customer::withoutCompanyScope()->find($this->old->id)->anonymised_at)->not->toBeNull()
        ->and(Customer::withoutCompanyScope()->find($this->otherOld->id)->anonymised_at)->toBeNull()
        ->and(Customer::withoutCompanyScope()->find($this->owing->id)->anonymised_at)->toBeNull();
});

test('a business with no retention period is never touched', function () {
    $this->artisan('privacy:retention', ['--apply' => true])->expectsOutput('No business has a data retention period set.')->assertSuccessful();
    $this->actingAs($this->owner)->post('/app/privacy/retention/apply')->assertRedirect();

    expect(Customer::withoutCompanyScope()->whereNotNull('anonymised_at')->count())->toBe(0);
});
