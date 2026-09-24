<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates registers (tills). company_id is copied from the branch. The factory does not keep the
 * one-main-till invariant: use the AddRegister action when a test depends on it.
 *
 * @extends Factory<Register>
 */
class RegisterFactory extends Factory
{
    protected $model = Register::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 99);

        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::withoutCompanyScope()->whereKey($attributes['branch_id'])->value('company_id'),
            'code' => Register::codeFor($number),
            'name' => 'Till '.$number,
            'is_main_till' => false,
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn () => ['branch_id' => $branch->id, 'company_id' => $branch->company_id]);
    }

    public function main(): static
    {
        return $this->state(fn () => ['is_main_till' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false, 'is_main_till' => false]);
    }
}
