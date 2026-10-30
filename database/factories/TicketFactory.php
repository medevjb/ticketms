<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\SlaPolicy;
use App\Models\SlaPolicyTarget;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'number' => 'TKT-'.now()->format('Y').'-'.fake()->unique()->numerify('##########'),
            'customer_id' => User::factory(),
            'created_by' => fn (array $attributes): int => $attributes['customer_id'],
            'category_id' => Category::factory(),
            'sla_policy_id' => SlaPolicy::factory()->published(),
            'calendar_version_id' => fn (array $attributes): int => SlaPolicy::query()->whereKey($attributes['sla_policy_id'])->firstOrFail()->calendar_version_id,
            'priority_id' => fn (array $attributes): int => SlaPolicyTarget::factory()->create(['sla_policy_id' => $attributes['sla_policy_id'], 'target_seconds' => 7200])->priority_id,
            'subject' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'status' => 'open',
            'sla_target_seconds' => 7200,
            'sla_started_at' => now(),
            'sla_warning_at' => now()->addMinutes(96),
            'sla_due_at' => now()->addHours(2),
        ];
    }

    public function waiting(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'waiting_customer',
            'sla_paused_at' => now(),
            'sla_due_at' => null,
            'sla_warning_at' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'resolved',
            'resolved_at' => now(),
            'sla_due_at' => null,
            'sla_warning_at' => null,
        ]);
    }

    public function closed(): static
    {
        return $this->resolved()->state(fn (array $attributes): array => [
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }
}
