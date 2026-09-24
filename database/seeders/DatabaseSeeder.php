<?php

namespace Database\Seeders;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
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

        $company = Company::factory()->create(['name' => 'Khan Mini Mart']);

        User::factory()->withCompany($company)->create([
            'name' => 'Demo Owner',
            'email' => 'test@example.com',
        ]);

        // Module 1.2: Leeds with 2 tills (01 is main) and Bradford with 1 till.
        foreach ([['LDS', 'Leeds', '12 Kirkgate, Leeds LS1 6BY', 2], ['BFD', 'Bradford', '48 Ivegate, Bradford BD1 1SQ', 1]] as [$code, $name, $address, $tills]) {
            $branch = Branch::factory()->forCompany($company)->create(['code' => $code, 'name' => $name, 'address' => $address]);

            for ($i = 1; $i <= $tills; $i++) {
                Register::factory()->forBranch($branch)->create([
                    'code' => Register::codeFor($i),
                    'name' => 'Till '.$i,
                    'is_main_till' => $i === 1,
                ]);
            }
        }

        $this->call(PlanSeeder::class);
    }
}
