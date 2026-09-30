<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HouseInvitationFactory extends Factory
{
    public function definition(): array
    {
        return ['house_id' => House::factory(), 'created_by' => User::factory(), 'token_hash' => hash('sha256', fake()->uuid()), 'apartment' => '24', 'expires_at' => now()->addHours(72)];
    }
}
