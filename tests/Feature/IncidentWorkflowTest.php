<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_can_assign_work_and_record_the_next_update(): void
    {
        [$house, , $dispatcher] = $this->makeHouseWithMembers();
        $incident = $this->makeIncident($house);

        $this->actingAs($dispatcher)
            ->patchJson("/houses/{$house->id}/incidents/{$incident->id}", [
                'status' => 'in_progress',
                'assignedTo' => 'Аварийная служба',
                'nextAction' => 'Проверить узел подачи горячей воды',
                'nextUpdateAt' => '2026-09-27T18:00:00+04:00',
            ])
            ->assertOk()
            ->assertJsonPath('incident.status', 'in_progress')
            ->assertJsonPath('incident.assignedTo', 'Аварийная служба')
            ->assertJsonPath('incident.nextAction', 'Проверить узел подачи горячей воды');

        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'status' => 'in_progress',
            'assigned_to' => 'Аварийная служба',
        ]);
        $this->assertDatabaseHas('incident_activities', [
            'incident_id' => $incident->id,
            'user_id' => $dispatcher->id,
            'event_type' => 'work_updated',
        ]);
    }

    public function test_resident_can_answer_the_scope_check_and_only_aggregate_results_are_visible(): void
    {
        [$house, $resident, , $neighbor] = $this->makeHouseWithMembers();
        $incident = $this->makeIncident($house);

        $this->actingAs($resident)
            ->postJson("/houses/{$house->id}/incidents/{$incident->id}/responses", [
                'stage' => 'scope',
                'answer' => 'problem_present',
            ])
            ->assertCreated()
            ->assertJsonPath('response.stage', 'scope')
            ->assertJsonPath('response.answer', 'problem_present');

        $this->actingAs($neighbor)
            ->getJson("/houses/{$house->id}/incidents")
            ->assertOk()
            ->assertJsonPath('incidents.0.scopeResponses.problemPresent', 1)
            ->assertJsonPath('incidents.0.scopeResponses.total', 1)
            ->assertJsonMissingPath('incidents.0.reports');
    }

    public function test_old_scope_answers_are_marked_stale_instead_of_counted_as_current(): void
    {
        [$house, $resident] = $this->makeHouseWithMembers();
        $incident = $this->makeIncident($house, 'in_progress');

        $this->actingAs($resident)
            ->postJson("/houses/{$house->id}/incidents/{$incident->id}/responses", [
                'stage' => 'scope',
                'answer' => 'problem_present',
            ])
            ->assertCreated();

        $this->travel(25)->hours();

        $this->actingAs($resident)
            ->getJson("/houses/{$house->id}/incidents")
            ->assertOk()
            ->assertJsonPath('incidents.0.scopeResponses.problemPresent', 0)
            ->assertJsonPath('incidents.0.scopeResponses.staleResponses', 1)
            ->assertJsonPath('incidents.0.myScopeResponseIsFresh', false);

        $this->actingAs($resident)
            ->postJson("/houses/{$house->id}/incidents/{$incident->id}/responses", [
                'stage' => 'scope',
                'answer' => 'service_working',
            ])
            ->assertOk();

        $this->actingAs($resident)
            ->getJson("/houses/{$house->id}/incidents")
            ->assertOk()
            ->assertJsonPath('incidents.0.scopeResponses.serviceWorking', 1)
            ->assertJsonPath('incidents.0.scopeResponses.staleResponses', 0)
            ->assertJsonPath('incidents.0.myScopeResponseIsFresh', true);
    }

    public function test_old_recovery_answers_are_counted_as_unconfirmed_and_marked_stale(): void
    {
        [$house, $resident, $dispatcher] = $this->makeHouseWithMembers();
        $incident = $this->makeIncident($house, 'in_progress');
        $this->addReport($incident, $resident, 'Нет горячей воды в квартире.');

        $this->actingAs($dispatcher)->patchJson("/houses/{$house->id}/incidents/{$incident->id}", [
            'status' => 'work_completed',
            'assignedTo' => 'Аварийная служба',
            'nextAction' => 'Проверить восстановление',
            'nextUpdateAt' => '2026-09-27T19:00:00+04:00',
        ])->assertOk();

        $this->actingAs($resident)->postJson("/houses/{$house->id}/incidents/{$incident->id}/responses", [
            'stage' => 'recovery',
            'answer' => 'restored',
        ])->assertCreated();

        $this->travel(25)->hours();

        $this->actingAs($resident)
            ->getJson("/houses/{$house->id}/incidents")
            ->assertOk()
            ->assertJsonPath('incidents.0.recovery.restored', 0)
            ->assertJsonPath('incidents.0.recovery.staleResponses', 1)
            ->assertJsonPath('incidents.0.recovery.noResponse', 1)
            ->assertJsonPath('incidents.0.myRecoveryResponseIsFresh', false);
    }

    public function test_repeating_the_same_scope_answer_refreshes_it_without_counting_twice(): void
    {
        $this->freezeTime();
        [$house, $resident] = $this->makeHouseWithMembers();
        $incident = $this->makeIncident($house, 'in_progress');
        $url = "/houses/{$house->id}/incidents/{$incident->id}/responses";
        $answer = ['stage' => 'scope', 'answer' => 'problem_present'];
        $createdAt = $this->actingAs($resident)->postJson($url, $answer)
            ->assertCreated()->json('response.createdAt');
        $this->travel(25)->hours();

        $this->postJson($url, $answer)->assertOk()
            ->assertJsonPath('response.createdAt', $createdAt)
            ->assertJsonPath('response.updatedAt', now()->toIso8601String());

        $this->getJson("/houses/{$house->id}/incidents")->assertOk()
            ->assertJsonPath('incidents.0.scopeResponses.problemPresent', 1)
            ->assertJsonPath('incidents.0.scopeResponses.total', 1)
            ->assertJsonPath('incidents.0.scopeResponses.staleResponses', 0)
            ->assertJsonPath('incidents.0.myScopeResponseIsFresh', true);
        $this->assertDatabaseCount('incident_responses', 1);
        $this->assertDatabaseCount('incident_activities', 1);
    }

    public function test_repeating_the_same_recovery_answer_refreshes_the_current_round(): void
    {
        $this->freezeTime();
        [$house, $resident] = $this->makeHouseWithMembers();
        $incident = $this->makeIncident($house, 'work_completed');
        $incident->update(['recovery_round' => 1]);
        $this->addReport($incident, $resident, 'Нет горячей воды.');
        $url = "/houses/{$house->id}/incidents/{$incident->id}/responses";
        $answer = ['stage' => 'recovery', 'answer' => 'restored'];
        $this->actingAs($resident)->postJson($url, $answer)->assertCreated();
        $this->travel(25)->hours();

        $this->postJson($url, $answer)->assertOk()
            ->assertJsonPath('response.round', 1)
            ->assertJsonPath('response.updatedAt', now()->toIso8601String());

        $this->getJson("/houses/{$house->id}/incidents")->assertOk()
            ->assertJsonPath('incidents.0.recovery.restored', 1)
            ->assertJsonPath('incidents.0.recovery.noResponse', 0)
            ->assertJsonPath('incidents.0.recovery.staleResponses', 0)
            ->assertJsonPath('incidents.0.myRecoveryResponseIsFresh', true);
        $this->assertDatabaseCount('incident_responses', 1);
        $this->assertDatabaseCount('incident_activities', 1);
    }

    public function test_dispatcher_completion_starts_recovery_check_and_tracks_missing_answers(): void
    {
        [$house, $resident, $dispatcher, $neighbor] = $this->makeHouseWithMembers();
        $incident = $this->makeIncident($house, 'in_progress');
        $this->addReport($incident, $resident, 'Нет горячей воды в квартире.');
        $this->addReport($incident, $neighbor, 'В моей квартире проблема сохраняется.');

        $this->actingAs($dispatcher)
            ->patchJson("/houses/{$house->id}/incidents/{$incident->id}", [
                'status' => 'work_completed',
                'assignedTo' => 'Аварийная служба',
                'nextAction' => 'Проверить восстановление у жителей',
                'nextUpdateAt' => '2026-09-27T19:00:00+04:00',
            ])
            ->assertOk()
            ->assertJsonPath('incident.status', 'work_completed')
            ->assertJsonPath('incident.recovery.round', 1)
            ->assertJsonPath('incident.recovery.totalReports', 2)
            ->assertJsonPath('incident.recovery.noResponse', 2)
            ->assertJsonPath('incident.recovery.restored', 0);

        $this->assertDatabaseHas('incident_activities', [
            'incident_id' => $incident->id,
            'user_id' => $dispatcher->id,
            'event_type' => 'work_completed',
        ]);

        $this->actingAs($resident)
            ->postJson("/houses/{$house->id}/incidents/{$incident->id}/responses", [
                'stage' => 'recovery',
                'answer' => 'restored',
            ])->assertCreated();

        $this->actingAs($neighbor)
            ->postJson("/houses/{$house->id}/incidents/{$incident->id}/responses", [
                'stage' => 'recovery',
                'answer' => 'problem_remains',
            ])->assertCreated();

        $this->actingAs($resident)
            ->getJson("/houses/{$house->id}/incidents")
            ->assertOk()
            ->assertJsonPath('incidents.0.recovery.restored', 1)
            ->assertJsonPath('incidents.0.recovery.problemRemains', 1)
            ->assertJsonPath('incidents.0.recovery.noResponse', 0)
            ->assertJsonPath('incidents.0.myRecoveryResponse', 'restored');
    }

    public function test_only_residents_who_reported_the_problem_can_answer_recovery_check(): void
    {
        [$house, $resident, $dispatcher, $neighbor] = $this->makeHouseWithMembers();
        $incident = $this->makeIncident($house, 'in_progress');
        $this->addReport($incident, $resident, 'Нет горячей воды в квартире.');

        $this->actingAs($dispatcher)
            ->patchJson("/houses/{$house->id}/incidents/{$incident->id}", [
                'status' => 'work_completed',
                'assignedTo' => 'Аварийная служба',
                'nextAction' => 'Проверить восстановление',
                'nextUpdateAt' => '2026-09-27T19:00:00+04:00',
            ])->assertOk();

        $this->actingAs($neighbor)
            ->postJson("/houses/{$house->id}/incidents/{$incident->id}/responses", [
                'stage' => 'recovery',
                'answer' => 'restored',
            ])
            ->assertForbidden();
    }

    public function test_resident_cannot_change_dispatcher_work_status(): void
    {
        [$house, $resident] = $this->makeHouseWithMembers();
        $incident = $this->makeIncident($house);

        $this->actingAs($resident)
            ->patchJson("/houses/{$house->id}/incidents/{$incident->id}", [
                'status' => 'in_progress',
                'assignedTo' => 'Житель',
                'nextAction' => 'Проверить',
                'nextUpdateAt' => '2026-09-27T19:00:00+04:00',
            ])
            ->assertForbidden();
    }

    public function test_reopening_and_recompletion_starts_a_fresh_recovery_round(): void
    {
        [$house, $resident, $dispatcher] = $this->makeHouseWithMembers();
        $incident = $this->makeIncident($house, 'in_progress');
        $this->addReport($incident, $resident, 'Нет горячей воды в квартире.');

        $this->actingAs($dispatcher)->patchJson("/houses/{$house->id}/incidents/{$incident->id}", [
            'status' => 'work_completed',
            'assignedTo' => 'Аварийная служба',
            'nextAction' => 'Ожидаем подтверждений',
            'nextUpdateAt' => '2026-09-27T19:00:00+04:00',
        ])->assertOk()->assertJsonPath('incident.recovery.round', 1);

        $this->actingAs($resident)->postJson("/houses/{$house->id}/incidents/{$incident->id}/responses", [
            'stage' => 'recovery',
            'answer' => 'problem_remains',
        ])->assertCreated();

        $this->actingAs($dispatcher)->patchJson("/houses/{$house->id}/incidents/{$incident->id}", [
            'status' => 'in_progress',
            'assignedTo' => 'Аварийная служба',
            'nextAction' => 'Повторная проверка узла',
            'nextUpdateAt' => '2026-09-27T20:00:00+04:00',
        ])->assertOk();

        $this->actingAs($dispatcher)->patchJson("/houses/{$house->id}/incidents/{$incident->id}", [
            'status' => 'work_completed',
            'assignedTo' => 'Аварийная служба',
            'nextAction' => 'Ожидаем повторных подтверждений',
            'nextUpdateAt' => '2026-09-27T21:00:00+04:00',
        ])->assertOk()->assertJsonPath('incident.recovery.round', 2);

        $this->actingAs($resident)
            ->getJson("/houses/{$house->id}/incidents")
            ->assertOk()
            ->assertJsonPath('incidents.0.recovery.problemRemains', 0)
            ->assertJsonPath('incidents.0.recovery.noResponse', 1)
            ->assertJsonPath('incidents.0.myRecoveryResponse', null);
    }

    /** @return array{House, User, User, User} */
    private function makeHouseWithMembers(): array
    {
        $house = House::factory()->create();
        $resident = User::factory()->create();
        $dispatcher = User::factory()->create();
        $neighbor = User::factory()->create();

        $house->members()->attach($resident, ['role' => 'resident']);
        $house->members()->attach($dispatcher, ['role' => 'dispatcher']);
        $house->members()->attach($neighbor, ['role' => 'resident']);

        return [$house, $resident, $dispatcher, $neighbor];
    }

    private function makeIncident(House $house, string $status = 'reported'): Incident
    {
        return $house->incidents()->create([
            'issue_type' => 'water',
            'location' => 'Подъезд 2',
            'status' => $status,
        ]);
    }

    private function addReport(Incident $incident, User $resident, string $details): void
    {
        $incident->reports()->create(['user_id' => $resident->id, 'details' => $details]);
    }
}
