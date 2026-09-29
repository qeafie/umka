<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentHouseMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_map_distinguishes_problems_working_service_and_unknown_floors_without_personal_data(): void
    {
        $this->freezeTime();
        $house = House::factory()->create(['layout' => [['entrance' => 1, 'floors' => 3], ['entrance' => 2, 'floors' => 1]]]);
        $resident = $this->addResident($house, 1, 1);
        $neighbor = $this->addResident($house, 1, 1);
        $silent = $this->addResident($house, 1, 2);
        $unlocated = $this->addResident($house, null, null);
        $incident = $this->incident($house);
        $this->answer($incident, $resident, 'problem_present');
        $this->answer($incident, $neighbor, 'service_working');
        $this->answer($incident, $unlocated, 'cannot_check');

        $map = $this->actingAs($resident)->getJson("/houses/{$house->id}/incidents")
            ->assertOk()->json('incidents.0.houseMap');

        $this->assertNotNull($map);
        $this->assertSame('scope', $map['stage']);
        $this->assertCount(4, $map['areas']);
        $this->assertSame([
            'entrance' => 1, 'floor' => 1, 'participants' => 2, 'problem' => 1, 'working' => 1,
            'cannotCheck' => 0, 'noResponse' => 0, 'stale' => 0,
            'lastCheckedAt' => now()->toIso8601String(), 'status' => 'mixed',
        ], $map['areas'][0]);
        $this->assertSame('unknown', $map['areas'][1]['status']);
        $this->assertSame(1, $map['areas'][1]['noResponse']);
        $this->assertSame(0, $map['areas'][2]['participants']);
        $this->assertSame('unknown', $map['areas'][2]['status']);
        $this->assertSame(1, $map['unlocated']['cannotCheck']);
        $this->assertStringNotContainsString($resident->name, json_encode($map));
        $this->assertStringNotContainsString('apartment', json_encode($map));
    }

    public function test_stale_checks_do_not_mark_a_floor_as_working_and_reconfirmation_refreshes_the_map(): void
    {
        $this->freezeTime();
        $house = House::factory()->create(['layout' => [['entrance' => 1, 'floors' => 1]]]);
        $resident = $this->addResident($house, 1, 1);
        $incident = $this->incident($house);
        $this->answer($incident, $resident, 'service_working');
        $this->travel(25)->hours();

        $this->actingAs($resident)->getJson("/houses/{$house->id}/incidents")->assertOk()
            ->assertJsonPath('incidents.0.houseMap.areas.0.status', 'unknown')
            ->assertJsonPath('incidents.0.houseMap.areas.0.working', 0)
            ->assertJsonPath('incidents.0.houseMap.areas.0.stale', 1)
            ->assertJsonPath('incidents.0.houseMap.areas.0.noResponse', 1);
        $this->postJson("/houses/{$house->id}/incidents/{$incident->id}/responses", ['stage' => 'scope', 'answer' => 'service_working'])->assertOk();

        $this->getJson("/houses/{$house->id}/incidents")->assertOk()
            ->assertJsonPath('incidents.0.houseMap.areas.0.status', 'confirmed')
            ->assertJsonPath('incidents.0.houseMap.areas.0.stale', 0)
            ->assertJsonPath('incidents.0.houseMap.areas.0.working', 1);
    }

    public function test_recovery_map_uses_only_reporters_and_the_current_round(): void
    {
        $this->freezeTime();
        $house = House::factory()->create(['layout' => [['entrance' => 2, 'floors' => 2]]]);
        $resident = $this->addResident($house, 2, 1);
        $silent = $this->addResident($house, 2, 1);
        $neighbor = $this->addResident($house, 2, 2);
        $this->addResident($house, 2, 2);
        $incident = $this->incident($house);
        $incident->update(['status' => 'work_completed', 'recovery_round' => 2]);
        foreach ([$resident, $silent, $neighbor] as $reporter) {
            $incident->reports()->create(['user_id' => $reporter->id, 'details' => 'Нет горячей воды.']);
        }
        $this->answer($incident, $resident, 'restored', 'recovery', 2);
        $this->answer($incident, $neighbor, 'problem_remains', 'recovery', 2);
        $this->answer($incident, $silent, 'restored', 'recovery', 1);
        $dispatcher = User::factory()->create();
        $house->members()->attach($dispatcher, ['role' => 'dispatcher']);

        $this->actingAs($dispatcher)->getJson("/houses/{$house->id}/incidents")->assertOk()
            ->assertJsonPath('incidents.0.houseMap.stage', 'recovery')
            ->assertJsonPath('incidents.0.houseMap.areas.0.status', 'partial')
            ->assertJsonPath('incidents.0.houseMap.areas.0.working', 1)
            ->assertJsonPath('incidents.0.houseMap.areas.0.noResponse', 1)
            ->assertJsonPath('incidents.0.houseMap.areas.1.status', 'problem')
            ->assertJsonPath('incidents.0.houseMap.areas.1.participants', 1);
    }

    public function test_map_is_private_to_the_house_and_ignores_unrelated_responses(): void
    {
        $house = House::factory()->create(['layout' => [['entrance' => 1, 'floors' => 1]]]);
        $resident = $this->addResident($house, 1, 1);
        $outsider = User::factory()->create();
        $incident = $this->incident($house);
        $this->answer($incident, $outsider, 'service_working');

        $this->getJson("/houses/{$house->id}/incidents")->assertUnauthorized();
        $this->actingAs($outsider)->getJson("/houses/{$house->id}/incidents")->assertForbidden();
        $this->actingAs($resident)->getJson("/houses/{$house->id}/incidents")->assertOk()
            ->assertJsonPath('incidents.0.houseMap.areas.0.working', 0)
            ->assertJsonPath('incidents.0.houseMap.areas.0.status', 'unknown');
    }

    public function test_unconfigured_locations_are_not_inferred_from_apartment_numbers_or_incident_text(): void
    {
        $house = House::factory()->create();
        $resident = $this->addResident($house, null, null);
        $incident = $this->incident($house);
        $this->answer($incident, $resident, 'problem_present');

        $this->actingAs($resident)->getJson("/houses/{$house->id}/incidents")->assertOk()
            ->assertJsonPath('incidents.0.houseMap.areas', [])
            ->assertJsonPath('incidents.0.houseMap.unlocated.problem', 1);
    }

    public function test_moving_a_resident_does_not_turn_their_old_answer_into_a_check_of_the_new_floor(): void
    {
        $this->freezeTime();
        $house = House::factory()->create(['layout' => [['entrance' => 1, 'floors' => 2]]]);
        $resident = $this->addResident($house, 1, 1);
        $incident = $this->incident($house);
        $url = "/houses/{$house->id}/incidents/{$incident->id}/responses";
        $this->actingAs($resident)->postJson($url, ['stage' => 'scope', 'answer' => 'service_working'])->assertCreated();
        $house->members()->updateExistingPivot($resident->id, ['floor' => 2]);

        $this->getJson("/houses/{$house->id}/incidents")->assertOk()
            ->assertJsonPath('incidents.0.houseMap.areas.1.status', 'unknown')
            ->assertJsonPath('incidents.0.houseMap.areas.1.working', 0);

        $this->postJson($url, ['stage' => 'scope', 'answer' => 'service_working'])->assertOk();
        $this->getJson("/houses/{$house->id}/incidents")->assertOk()
            ->assertJsonPath('incidents.0.houseMap.areas.1.status', 'confirmed');
    }

    private function addResident(House $house, ?int $entrance, ?int $floor): User
    {
        $resident = User::factory()->create();
        $house->members()->attach($resident, ['role' => 'resident', 'apartment' => '24', 'entrance' => $entrance, 'floor' => $floor]);

        return $resident;
    }

    private function incident(House $house): Incident
    {
        return Incident::factory()->create(['house_id' => $house->id, 'issue_type' => 'water', 'location' => 'Подъезд 2', 'status' => 'in_progress']);
    }

    private function answer(Incident $incident, User $user, string $answer, string $stage = 'scope', int $round = 0): void
    {
        $membership = $incident->house->members()->whereKey($user->id)->first();
        $incident->responses()->create([
            'user_id' => $user->id, 'answer' => $answer, 'stage' => $stage, 'round' => $round,
            'entrance' => $membership?->pivot->entrance, 'floor' => $membership?->pivot->floor,
        ]);
    }
}
