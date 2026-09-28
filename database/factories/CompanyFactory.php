<?php

namespace Database\Factories;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' Ltd',
            'vat_number' => 'GB'.fake()->numerify('#########'),
            'company_number' => fake()->numerify('########'),
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'status' => CompanyStatus::Active,
        ];
    }

    public function status(CompanyStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function trial(int $days = 7): static
    {
        return $this->state(fn () => ['status' => CompanyStatus::Trial, 'trial_ends_at' => now()->addDays($days)]);
    }

    public function suspended(string $reason = 'Unpaid invoice'): static
    {
        return $this->state(fn () => [
            'status' => CompanyStatus::Suspended,
            'suspended_at' => now(),
            'suspended_from_status' => CompanyStatus::Active,
            'suspension_reason' => $reason,
        ]);
    }

    public function cancelled(string $reason = 'Closed the shop'): static
    {
        return $this->state(fn () => [
            'status' => CompanyStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    /**
     * Give the company a branch with the given number of tills (the first is the main till).
     */
    public function withBranch(string $code = 'MAIN', string $name = 'Main shop', int $tills = 1): static
    {
        return $this->afterCreating(function (Company $company) use ($code, $name, $tills) {
            $branch = Branch::factory()->forCompany($company)->create(['code' => $code, 'name' => $name, 'max_registers' => max(1, $tills)]);

            for ($i = 1; $i <= $tills; $i++) {
                Register::factory()->forBranch($branch)->create([
                    'code' => Register::codeFor($i),
                    'name' => 'Till '.$i,
                    'is_main_till' => $i === 1,
                ]);
            }
        });
    }

    /**
     * Attach a user as a member once the company is created.
     */
    public function withMember(User $user, CompanyRole $role = CompanyRole::Owner, bool $active = true): static
    {
        return $this->afterCreating(function (Company $company) use ($user, $role, $active) {
            $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => $active]);
        });
    }
}
