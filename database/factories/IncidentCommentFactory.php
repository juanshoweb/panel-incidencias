<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentComment>
 */
class IncidentCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'author_name' => fake()->name(),
            'body' => fake()->paragraph(),
        ];
    }
}
