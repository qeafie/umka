<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HousingIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_can_list_only_their_assigned_houses_and_house_role(): void
    {
        $resident = User::factory()->create();
        $assignedHouse = House::factory()->create();
        $unrelatedHouse = House::factory()->create();
        $assignedHouse->members()->attach($resident, ['role' => 'resident', 'apartment' => '24']);

        $this->actingAs($resident)
            ->getJson('/my/houses')
            ->assertOk()
            ->assertJsonCount(1, 'houses')
            ->assertJsonPath('houses.0.id', $assignedHouse->id)
            ->assertJsonPath('houses.0.role', 'resident')
            ->assertJsonMissing(['id' => $unrelatedHouse->id]);
    }

    public function test_resident_can_report_a_problem_in_their_house(): void
    {
        $resident = User::factory()->create();
        $house = House::factory()->create();
        $house->members()->attach($resident, ['role' => 'resident', 'apartment' => '24']);

        $this->actingAs($resident)
            ->postJson("/houses/{$house->id}/incidents", [
                'issueType' => 'water',
                'location' => 'Подъезд 2',
                'details' => 'Нет горячей воды с утра.',
            ])
            ->assertCreated()
            ->assertJsonPath('incident.issueType', 'water')
            ->assertJsonPath('incident.location', 'Подъезд 2')
            ->assertJsonPath('incident.reportCount', 1);

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseHas('incident_reports', [
            'user_id' => $resident->id,
            'details' => 'Нет горячей воды с утра.',
        ]);
    }

    public function test_resident_can_explicitly_join_an_existing_problem_in_the_same_house(): void
    {
        $resident = User::factory()->create();
        $firstResident = User::factory()->create();
        $house = House::factory()->create();
        $house->members()->attach($resident, ['role' => 'resident']);
        $house->members()->attach($firstResident, ['role' => 'resident']);

        $incidentResponse = $this->actingAs($firstResident)->postJson("/houses/{$house->id}/incidents", [
            'issueType' => 'water',
            'location' => 'Подъезд 2',
            'details' => 'Нет горячей воды.',
        ])->assertCreated();

        $incidentId = $incidentResponse->json('incident.id');

        $this->actingAs($resident)
            ->postJson("/houses/{$house->id}/incidents", [
                'issueType' => 'water',
                'location' => 'Подъезд 2',
                'details' => 'В моей квартире тоже нет горячей воды.',
                'incidentId' => $incidentId,
            ])
            ->assertCreated()
            ->assertJsonPath('incident.id', $incidentId)
            ->assertJsonPath('incident.reportCount', 2);

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('incident_reports', 2);
    }

    public function test_resident_cannot_view_or_report_problems_for_an_unrelated_house(): void
    {
        $resident = User::factory()->create();
        $house = House::factory()->create();

        $this->actingAs($resident)
            ->getJson("/houses/{$house->id}/incidents")
            ->assertForbidden();

        $this->postJson("/houses/{$house->id}/incidents", [
            'issueType' => 'water',
            'location' => 'Подъезд 2',
            'details' => 'Нет горячей воды.',
        ])->assertForbidden();

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_resident_cannot_join_a_problem_from_another_house(): void
    {
        $resident = User::factory()->create();
        $otherResident = User::factory()->create();
        $house = House::factory()->create();
        $otherHouse = House::factory()->create();
        $house->members()->attach($resident, ['role' => 'resident']);
        $otherHouse->members()->attach($otherResident, ['role' => 'resident']);

        $incidentId = $this->actingAs($otherResident)
            ->postJson("/houses/{$otherHouse->id}/incidents", [
                'issueType' => 'water',
                'location' => 'Подъезд 2',
                'details' => 'Нет горячей воды.',
            ])->assertCreated()->json('incident.id');

        $this->actingAs($resident)
            ->postJson("/houses/{$house->id}/incidents", [
                'issueType' => 'water',
                'location' => 'Подъезд 2',
                'details' => 'У нас тоже нет горячей воды.',
                'incidentId' => $incidentId,
            ])
            ->assertNotFound();
    }

    public function test_resident_sees_only_a_summary_while_dispatcher_can_review_report_details(): void
    {
        $resident = User::factory()->create();
        $dispatcher = User::factory()->create();
        $house = House::factory()->create();
        $house->members()->attach($resident, ['role' => 'resident']);
        $house->members()->attach($dispatcher, ['role' => 'dispatcher']);

        $incidentId = $this->actingAs($resident)
            ->postJson("/houses/{$house->id}/incidents", [
                'issueType' => 'water',
                'location' => 'Подъезд 2',
                'details' => 'В моей квартире нет горячей воды.',
            ])->assertCreated()->json('incident.id');

        $this->actingAs($resident)
            ->getJson("/houses/{$house->id}/incidents")
            ->assertOk()
            ->assertJsonMissingPath('incidents.0.reports')
            ->assertJsonPath('incidents.0.reportCount', 1);

        $this->actingAs($dispatcher)
            ->getJson("/houses/{$house->id}/incidents")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $incidentId)
            ->assertJsonPath('incidents.0.reports.0.details', 'В моей квартире нет горячей воды.');
    }
}
