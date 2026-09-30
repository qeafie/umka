<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Incident;
use App\Models\IncidentReport;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\User;
use Database\Seeders\DemoHousingDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoHousingDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_a_reusable_house_scenario_for_residents_and_dispatcher(): void
    {
        $this->seed(DemoHousingDataSeeder::class);

        $this->assertDatabaseCount('houses', 1);
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('house_memberships', 4);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('incident_reports', 2);

        $resident = User::query()->where('max_user_id', config('demo.resident_max_user_id'))->firstOrFail();
        $dispatcher = User::query()->where('max_user_id', config('demo.dispatcher_max_user_id'))->firstOrFail();
        $administrator = User::query()->where('max_user_id', config('demo.house_admin_max_user_id'))->firstOrFail();
        $incident = Incident::query()->withCount('reports')->firstOrFail();

        $this->assertSame('resident', $resident->houses()->firstOrFail()->pivot->role);
        $this->assertSame('dispatcher', $dispatcher->houses()->firstOrFail()->pivot->role);
        $this->assertSame('house_admin', $administrator->houses()->firstOrFail()->pivot->role);
        $this->assertSame(2, $incident->reports_count);
        $this->assertDatabaseHas('meters', ['user_id' => $resident->id, 'name' => 'Холодная вода']);
        $this->assertDatabaseHas('meter_readings', ['user_id' => $resident->id, 'value' => '124.375']);
    }

    public function test_demo_seeder_can_be_run_repeatedly_without_duplicate_demo_records(): void
    {
        $this->seed(DemoHousingDataSeeder::class);
        $this->seed(DemoHousingDataSeeder::class);

        $this->assertSame(1, House::query()->count());
        $this->assertSame(4, User::query()->count());
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(2, IncidentReport::query()->count());
        $this->assertSame(1, Meter::query()->count());
        $this->assertSame(1, MeterReading::query()->count());
    }

    public function test_demo_house_has_explicit_entrances_and_resident_floors_for_the_map(): void
    {
        $this->seed(DemoHousingDataSeeder::class);
        $resident = User::query()->where('max_user_id', config('demo.resident_max_user_id'))->firstOrFail();

        $this->actingAs($resident)->getJson('/my/houses')->assertOk()
            ->assertJsonPath('houses.0.layout', [['entrance' => 1, 'floors' => 5], ['entrance' => 2, 'floors' => 5]]);

        $this->assertDatabaseHas('house_memberships', ['user_id' => $resident->id, 'entrance' => 2, 'floor' => 2]);
    }
}
