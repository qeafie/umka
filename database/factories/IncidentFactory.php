<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'house_id' => House::factory(),
            'issue_type' => 'water',
            'location' => 'Подъезд 2',
            'status' => 'reported',
        ];
    }
}
