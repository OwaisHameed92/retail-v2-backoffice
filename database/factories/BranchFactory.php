<?php

namespace Database\Factories;

use App\Domain\Tenancy\Enums\Nation;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates branches with an explicit company_id (works without a current company).
 *
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $town = fake()->city();

        return [
            'company_id' => Company::factory(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => $town,
            'address' => fake()->streetAddress().', '.$town,
            'phone' => fake()->numerify('0113 ### ####'),
            'vat_number' => null,
            'nation' => Nation::England,
            'licensed_hours_json' => null,
            'is_drs_return_point' => false,
            'area_m2' => null,
            'is_active' => true,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
