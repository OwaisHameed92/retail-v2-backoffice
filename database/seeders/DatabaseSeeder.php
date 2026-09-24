<?php

namespace Database\Seeders;

use App\Domain\Licensing\Actions\IssueLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local demo data only. Admins are never seeded: create one with `php artisan admin:create`.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        // Plans first: the demo tills are licensed on Standard (module 1.3).
        $this->call(PlanSeeder::class);
        $standard = Plan::query()->where('code', 'standard')->firstOrFail();

        $company = Company::factory()->create(['name' => 'Khan Mini Mart']);
        $company->plan_id = $standard->id;
        $company->save();

        User::factory()->withCompany($company)->create([
            'name' => 'Demo Owner',
            'email' => 'test@example.com',
        ]);

        // Module 1.2: Leeds with 2 tills (01 is main) and Bradford with 1 till.
        $registers = [];
        foreach ([['LDS', 'Leeds', '12 Kirkgate, Leeds LS1 6BY', 2], ['BFD', 'Bradford', '48 Ivegate, Bradford BD1 1SQ', 1]] as [$code, $name, $address, $tills]) {
            $branch = Branch::factory()->forCompany($company)->create(['code' => $code, 'name' => $name, 'address' => $address]);

            for ($i = 1; $i <= $tills; $i++) {
                $registers[] = Register::factory()->forBranch($branch)->create([
                    'code' => Register::codeFor($i),
                    'name' => 'Till '.$i,
                    'is_main_till' => $i === 1,
                ]);
            }
        }

        $this->licenceTills($registers, $standard);

        // Module 1.8: invoices and payments for the demo tenants.
        $this->call(BillingSeeder::class);

        // Module 1.6: a handful of trial requests in every status.
        $this->call(LeadSeeder::class);
    }

    /**
     * Module 1.3: one licence per demo till on the Standard plan. The plain keys are thrown away (nothing secret
     * is printed); reissue a key from the admin licence page to try a till. To show every state on the admin
     * screens, the first two licences pretend module 1.5 activated them: Leeds 01 on day 3 of its trial,
     * Leeds 02 paid for the month. Bradford 01 stays "issued".
     *
     * @param  list<Register>  $registers
     */
    private function licenceTills(array $registers, Plan $plan): void
    {
        $issue = app(IssueLicence::class);
        $now = CarbonImmutable::now();

        foreach ($registers as $index => $register) {
            $licence = $issue->handle($register, $plan)->licence;

            $demo = match ($index) {
                0 => ['status' => LicenceStatus::Trial, 'activated_at' => $now->subDays(2), 'trial_ends_at' => $now->subDays(2)->addDays($plan->trial_days), 'device' => 'LEEDS-TILL-01'],
                1 => ['status' => LicenceStatus::Active, 'activated_at' => $now->subDays(40), 'trial_ends_at' => $now->subDays(33), 'expires_at' => $now->addDays(21)->endOfDay(), 'grace_days' => $plan->grace_days, 'device' => 'LEEDS-TILL-02'],
                default => null,
            };

            if ($demo === null) {
                continue;
            }

            $device = $demo['device'];
            unset($demo['device']);

            Licence::withoutCompanyScope()->whereKey($licence->id)->firstOrFail()->forceFill($demo + [
                'device_id' => 'DEMO-'.strtoupper(substr(md5($device), 0, 12)),
                'device_name' => $device,
                'bound_at' => $demo['activated_at'],
                'last_check_in_at' => $now->subMinutes(42 + $index * 90),
                'last_app_version' => '1.0.0',
                'last_ip' => '203.0.113.'.(10 + $index),
            ])->save();
        }
    }
}
