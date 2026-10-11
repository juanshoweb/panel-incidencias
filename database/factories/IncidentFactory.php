<?php

namespace Database\Factories;

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(6),
            'description' => fake()->optional()->paragraph(),
            'status' => IncidentStatus::Open,
            'priority' => fake()->randomElement(IncidentPriority::cases()),
            'requester_name' => fake()->name(),
            'assigned_to' => fake()->optional()->name(),
            'closed_at' => null,
        ];
    }

    public function inReview(): static
    {
        return $this->state(fn () => [
            'status' => IncidentStatus::Review,
            'closed_at' => null,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'status' => IncidentStatus::Blocked,
            'closed_at' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => IncidentStatus::Resolved,
            'closed_at' => now(),
        ]);
    }
}
