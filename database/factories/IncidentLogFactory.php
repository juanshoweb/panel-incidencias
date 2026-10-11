<?php

namespace Database\Factories;

use App\Enums\IncidentLogAction;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentLog>
 */
class IncidentLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'action' => IncidentLogAction::StatusChanged,
            'old_value' => IncidentStatus::Open->value,
            'new_value' => IncidentStatus::Review->value,
            'user_name' => fake()->name(),
            'metadata' => ['source' => 'factory'],
        ];
    }

    public function reopened(): static
    {
        return $this->state(fn () => [
            'action' => IncidentLogAction::Reopened,
            'old_value' => IncidentStatus::Resolved->value,
            'new_value' => IncidentStatus::Open->value,
            'metadata' => ['reason' => fake()->sentence()],
        ]);
    }
}
