<?php

namespace App\Domain\Demo\Actions;

use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The demo businesses `demo:seed` fills when no --company is given: Khan Mini Mart (Leeds with two tills, Bradford
 * with one) and Singh Family Stores (Wolverhampton, two tills). A business that already exists is used as it is;
 * a missing one is made (no login, no e-mail sent). Never in production.
 */
final class EnsureDemoTenants
{
    /** Name => [legal name, town, postcode, shops: [code, name, address, phone, tills]]. */
    public const TENANTS = [
        'Khan Mini Mart' => ['Khan Mini Mart Ltd', 'Leeds', 'LS1 6BY', [
            ['LDS', 'Leeds', '12 Kirkgate, Leeds LS1 6BY', '0113 496 0011', 2],
            ['BFD', 'Bradford', '48 Ivegate, Bradford BD1 1SQ', '01274 496 012', 1],
        ]],
        'Singh Family Stores' => ['Singh Family Stores Ltd', 'Wolverhampton', 'WV1 4AN', [
            ['WOL', 'Wolverhampton', '7 Dudley Street, Wolverhampton WV1 4AN', '01902 496 013', 2],
        ]],
    ];

    /**
     * @return list<Company>
     */
    public function handle(): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo businesses are never made in production.');
        }

        $companies = [];

        foreach (self::TENANTS as $name => $tenant) {
            $companies[] = Company::query()->where('name', $name)->first() ?? $this->create($name, ...$tenant);
        }

        return $companies;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int}>  $shops
     */
    private function create(string $name, string $legal, string $town, string $postcode, array $shops): Company
    {
        return DB::transaction(function () use ($name, $legal, $town, $postcode, $shops) {
            $company = new Company([
                'name' => $name, 'legal_name' => $legal, 'vat_number' => 'GB'.(100000000 + abs(crc32($name)) % 800000000),
                'company_number' => (string) (10000000 + abs(crc32($legal)) % 8999999), 'address' => $shops[0][2], 'town' => $town,
                'postcode' => $postcode, 'phone' => $shops[0][3], 'email' => 'hello@'.strtolower(str_replace(' ', '', $name)).'.example.co.uk',
            ]);
            $company->status = CompanyStatus::Active;
            $company->activated_at = now()->subMonths(4);
            $company->plan_id = Plan::query()->where('code', 'standard')->value('id');
            $company->multi_branch = count($shops) > 1;
            $company->max_branches = max(1, count($shops));
            $company->save();

            foreach ($shops as [$code, $shopName, $address, $phone, $tills]) {
                $branch = new Branch(['code' => $code, 'name' => $shopName, 'address' => $address, 'phone' => $phone]);
                $branch->forceFill(['company_id' => $company->id, 'is_active' => true, 'max_registers' => $tills])->save();

                for ($i = 1; $i <= $tills; $i++) {
                    (new Register)->forceFill([
                        'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => Register::codeFor($i), 'name' => 'Till '.$i,
                        'is_main_till' => $i === 1, 'is_active' => true,
                    ])->save();
                }
            }

            return $company;
        });
    }
}
