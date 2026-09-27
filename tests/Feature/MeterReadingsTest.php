<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeterReadingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_or_add_meters(): void
    {
        $this->getJson('/meters')->assertUnauthorized();
        $this->postJson('/meters', ['name' => 'Счётчик', 'service' => 'cold_water'])->assertUnauthorized();

        $this->assertDatabaseCount('meters', 0);
    }

    public function test_resident_can_add_a_meter_and_record_a_reading(): void
    {
        $resident = User::factory()->create();
        $this->actingAs($resident);

        $this->postJson('/meters', [
            'name' => 'Холодная вода, ванная',
            'service' => 'cold_water',
            'serialNumber' => 'ХВ-2048',
        ])
            ->assertCreated()
            ->assertJsonPath('meter.name', 'Холодная вода, ванная')
            ->assertJsonPath('meter.unit', 'м³');

        $meterId = $this->app['db']->table('meters')->value('id');

        $this->postJson("/meters/{$meterId}/readings", ['reading' => '124.375'])
            ->assertCreated()
            ->assertJsonPath('reading.value', '124.375')
            ->assertJsonPath('reading.status', 'saved_locally');

        $this->assertDatabaseHas('meter_readings', [
            'meter_id' => $meterId,
            'user_id' => $resident->id,
            'value' => '124.375',
        ]);
    }

    public function test_resident_can_only_see_their_own_meters_and_reading_history(): void
    {
        $resident = User::factory()->create();
        $otherResident = User::factory()->create();
        $this->actingAs($resident);

        $this->postJson('/meters', ['name' => 'Электричество', 'service' => 'electricity'])->assertCreated();
        $this->actingAs($otherResident);
        $this->postJson('/meters', ['name' => 'Газ', 'service' => 'gas'])->assertCreated();

        $this->actingAs($resident)
            ->getJson('/meters')
            ->assertOk()
            ->assertJsonCount(1, 'meters')
            ->assertJsonPath('meters.0.name', 'Электричество');
    }

    public function test_resident_cannot_record_a_reading_for_another_residents_meter(): void
    {
        $owner = User::factory()->create();
        $otherResident = User::factory()->create();
        $this->actingAs($owner);
        $this->postJson('/meters', ['name' => 'Счётчик', 'service' => 'cold_water'])->assertCreated();
        $meterId = $this->app['db']->table('meters')->value('id');

        $this->actingAs($otherResident)
            ->postJson("/meters/{$meterId}/readings", ['reading' => '1.000'])
            ->assertNotFound();
    }

    public function test_reading_must_not_be_lower_than_the_previous_reading(): void
    {
        $resident = User::factory()->create();
        $this->actingAs($resident);
        $this->postJson('/meters', ['name' => 'Горячая вода', 'service' => 'hot_water'])->assertCreated();
        $meterId = $this->app['db']->table('meters')->value('id');
        $this->postJson("/meters/{$meterId}/readings", ['reading' => '25'])->assertCreated();

        $this->postJson("/meters/{$meterId}/readings", ['reading' => '24.999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reading']);

        $this->assertDatabaseCount('meter_readings', 1);
    }

    public function test_meter_service_and_reading_format_are_validated(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/meters', ['name' => 'Мой счётчик', 'service' => 'heating'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service']);
    }
}
