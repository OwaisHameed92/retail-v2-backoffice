<?php

namespace Database\Factories;

use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Enums\BusinessType;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Leads for tests and the local seeder. States set fields the actions own (status, assignment…) directly:
 * tests of the actions themselves must go through the actions.
 *
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_name' => fake()->lastName().' '.fake()->randomElement(['Mini Mart', 'News', 'Convenience Store', 'Food & Wine', 'Stores']),
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '07700 9'.fake()->unique()->numerify('#####'),
            'town' => fake()->randomElement(['Leeds', 'Bradford', 'Manchester', 'Birmingham', 'Leicester']),
            'postcode' => 'LS1 6BX',
            'shops_count' => 1,
            'tills_count' => 2,
            'business_type' => BusinessType::Convenience,
            'source' => LeadSource::Website,
        ];
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function contacted(): static
    {
        return $this->state(fn () => ['status' => LeadStatus::Contacted, 'contacted_at' => now()->subDay(), 'last_contacted_at' => now()->subDay()]);
    }

    public function rejected(string $reason = 'Not a retail business'): static
    {
        return $this->state(fn () => ['status' => LeadStatus::Rejected, 'rejection_reason' => $reason, 'rejected_at' => now()]);
    }

    public function assignedTo(Admin $admin): static
    {
        return $this->state(fn () => ['assigned_admin_id' => $admin->id]);
    }

    public function followUpAt(\DateTimeInterface $at): static
    {
        return $this->state(fn () => ['follow_up_at' => $at]);
    }
}
