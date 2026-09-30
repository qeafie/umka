<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Incident;
use App\Models\MaxNotification;
use App\Models\User;
use App\Services\Max\MaxDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MaxNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        config(['services.max.bot_token' => 'test-token', 'services.max.webhook_secret' => 'test-secret']);
        Http::preventStrayRequests();
        $house = House::factory()->create(['layout' => [['entrance' => 2, 'floors' => 5]]]);
        $dispatcher = User::factory()->create();
        $resident = User::factory()->create(['max_user_id' => '1234']);
        $house->members()->attach($dispatcher, ['role' => 'dispatcher']);
        $house->members()->attach($resident, ['role' => 'resident', 'entrance' => 2, 'floor' => 4]);
        $incident = Incident::factory()->create(['house_id' => $house->id, 'status' => 'in_progress']);

        return [$house, $dispatcher, $resident, $incident];
    }

    private function subscribe(House $house, User $resident): void
    {
        $this->actingAs($resident)->putJson("/houses/{$house->id}/notifications", ['enabled' => true])->assertOk();
    }

    private function poll(House $house, User $dispatcher, Incident $incident): void
    {
        $this->actingAs($dispatcher)->postJson("/houses/{$house->id}/incidents/{$incident->id}/surveys", ['entrance' => 2, 'floorFrom' => 3, 'floorTo' => 5])->assertOk();
    }

    public function test_resident_opt_in_is_explicit_and_scoped_to_their_house(): void
    {
        [$house, $dispatcher, $resident] = $this->scenario();
        $this->actingAs($resident)->getJson("/houses/{$house->id}/notifications")->assertOk()->assertJsonPath('enabled', false);
        $this->subscribe($house, $resident);
        $this->getJson("/houses/{$house->id}/notifications")->assertJsonPath('enabled', true);
        $this->putJson('/houses/'.House::factory()->create()->id.'/notifications', ['enabled' => true])->assertForbidden();
        $this->actingAs($dispatcher)->putJson("/houses/{$house->id}/notifications", ['enabled' => true])->assertForbidden();
    }

    public function test_scope_poll_targets_consented_residents_without_fresh_answers_and_deduplicates(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $others = User::factory()->count(3)->create();
        foreach ($others as $index => $other) {
            $other->update(['max_user_id' => (string) (5000 + $index)]);
            $house->members()->attach($other, ['role' => 'resident', 'entrance' => 2, 'floor' => $index === 0 ? 1 : 4, 'notifications_enabled' => $index !== 1]);
        }
        $incident->responses()->create(['user_id' => $others[2]->id, 'stage' => 'scope', 'round' => 0, 'answer' => 'service_working', 'entrance' => 2, 'floor' => 4]);
        $this->poll($house, $dispatcher, $incident);
        $this->poll($house, $dispatcher, $incident);
        $this->assertDatabaseCount('max_notifications', 1);
        $this->assertDatabaseHas('max_notifications', ['user_id' => $resident->id, 'kind' => 'scope', 'status' => 'pending']);
        Http::assertNothingSent();
    }

    public function test_survey_requires_dispatcher_of_incident_house_and_valid_layout(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $payload = ['entrance' => 2, 'floorFrom' => 1, 'floorTo' => 5];
        $this->actingAs($resident)->postJson("/houses/{$house->id}/incidents/{$incident->id}/surveys", $payload)->assertForbidden();
        $other = Incident::factory()->create();
        $this->actingAs($dispatcher)->postJson("/houses/{$house->id}/incidents/{$other->id}/surveys", $payload)->assertNotFound();
        $this->postJson("/houses/{$house->id}/incidents/{$incident->id}/surveys", [...$payload, 'floorTo' => 6])->assertUnprocessable();
    }

    public function test_work_completion_notifies_only_reporting_residents_once_per_round(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $incident->reports()->create(['user_id' => $resident->id, 'details' => 'Private report']);
        $payload = ['status' => 'work_completed', 'assignedTo' => 'Мастер', 'nextAction' => 'Проверяем восстановление', 'nextUpdateAt' => now()->addHour()->toIso8601String()];
        $this->actingAs($dispatcher)->patchJson("/houses/{$house->id}/incidents/{$incident->id}", $payload)->assertOk();
        $this->patchJson("/houses/{$house->id}/incidents/{$incident->id}", $payload)->assertOk();
        $this->assertDatabaseCount('max_notifications', 1);
        $this->assertDatabaseHas('max_notifications', ['kind' => 'recovery', 'round' => 1, 'user_id' => $resident->id]);
    }

    public function test_transport_sends_documented_payload_and_does_not_resend_successful_delivery(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        Http::fake(['platform-api2.max.ru/*' => Http::response(['message' => ['body' => ['mid' => 'message-1']]], 200)]);
        $delivery = MaxNotification::firstOrFail();
        app(MaxDeliveryService::class)->deliver($delivery->id);
        app(MaxDeliveryService::class)->deliver($delivery->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'user_id=1234') && $request->hasHeader('Authorization', 'test-token') && $request['attachments'][0]['payload']['buttons'][0][0]['type'] === 'callback');
        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_delivery_retries_transient_failure_and_cancels_after_opt_out(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        Http::fake(['platform-api2.max.ru/*' => Http::response([], 503)]);
        $delivery = MaxNotification::firstOrFail();
        app(MaxDeliveryService::class)->deliver($delivery->id);
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->actingAs($resident)->putJson("/houses/{$house->id}/notifications", ['enabled' => false])->assertOk();
        $this->travel(10)->minutes();
        app(MaxDeliveryService::class)->deliver($delivery->id);
        Http::assertSentCount(1);
        $this->assertSame('cancelled', $delivery->fresh()->status);
    }

    public function test_callback_uses_authenticated_recipient_and_replay_does_not_refresh_answer(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        Http::fake(['platform-api2.max.ru/*' => Http::response(['success' => true, 'message' => ['body' => ['mid' => 'm1']]], 200)]);
        $delivery = MaxNotification::firstOrFail();
        app(MaxDeliveryService::class)->deliver($delivery->id);
        $event = ['update_type' => 'message_callback', 'callback' => ['callback_id' => 'event-1', 'user' => ['user_id' => 1234], 'payload' => 'umka:'.$delivery->token.':problem_present']];
        $this->postJson('/api/max/webhook', $event)->assertUnauthorized();
        $this->withHeader('X-Max-Bot-Api-Secret', 'test-secret')->postJson('/api/max/webhook', $event)->assertOk();
        $answer = $incident->responses()->firstOrFail();
        $this->assertSame('problem_present', $answer->answer);
        $this->travel(2)->hours();
        $this->postJson('/api/max/webhook', $event)->assertOk();
        $this->assertTrue($answer->updated_at->equalTo($answer->fresh()->updated_at));
        $this->assertDatabaseCount('incident_responses', 1);
        $forged = $event;
        $forged['callback']['callback_id'] = 'event-2';
        $forged['callback']['user']['user_id'] = 9999;
        $this->postJson('/api/max/webhook', $forged)->assertOk();
        $this->assertDatabaseCount('incident_responses', 1);
    }

    public function test_dispatcher_can_inspect_delivery_counts_but_resident_cannot(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        $this->getJson("/houses/{$house->id}/incidents/{$incident->id}/surveys")->assertOk()->assertJsonPath('counts.pending', 1)->assertJsonMissingPath('users');
        $this->actingAs($resident)->getJson("/houses/{$house->id}/incidents/{$incident->id}/surveys")->assertForbidden();
    }

    public function test_callback_can_confirm_a_delivery_whose_http_result_is_still_unknown(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        $delivery = MaxNotification::firstOrFail();
        $delivery->update(['status' => 'uncertain', 'attempts' => 1]);
        Http::fake(['platform-api2.max.ru/*' => Http::response(['success' => true], 200)]);
        $event = ['update_type' => 'message_callback', 'callback' => ['callback_id' => 'uncertain-1', 'user' => ['user_id' => 1234], 'payload' => 'umka:'.$delivery->token.':service_working']];
        $this->withHeader('X-Max-Bot-Api-Secret', 'test-secret')->postJson('/api/max/webhook', $event)->assertOk();
        $this->assertDatabaseHas('incident_responses', ['user_id' => $resident->id, 'answer' => 'service_working']);
        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_timeout_is_uncertain_and_not_automatically_retried(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        Http::fake(['platform-api2.max.ru/*' => Http::failedConnection()]);
        $delivery = MaxNotification::firstOrFail();
        app(MaxDeliveryService::class)->deliver($delivery->id);
        $this->assertSame('uncertain', $delivery->fresh()->status);
        $this->travel(2)->minutes();
        app(MaxDeliveryService::class)->deliver($delivery->id);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_removed_then_rejoined_member_cannot_receive_old_delivery(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        $house->members()->detach($resident);
        $house->members()->attach($resident, ['role' => 'resident', 'entrance' => 2, 'floor' => 4, 'notifications_enabled' => true]);
        $delivery = MaxNotification::firstOrFail();
        app(MaxDeliveryService::class)->deliver($delivery->id);
        Http::assertNothingSent();
        $this->assertSame('cancelled', $delivery->fresh()->status);
    }

    public function test_bot_stopped_revokes_consent_and_cancels_queued_messages(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        $this->withHeader('X-Max-Bot-Api-Secret', 'test-secret')->postJson('/api/max/webhook', ['update_type' => 'bot_stopped', 'user' => ['user_id' => 1234]])->assertOk();
        $this->assertDatabaseHas('house_memberships', ['user_id' => $resident->id, 'notifications_enabled' => false]);
        $this->assertDatabaseHas('max_notifications', ['user_id' => $resident->id, 'status' => 'cancelled']);
    }

    public function test_recovery_callback_from_previous_round_cannot_confirm_new_work(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $incident->reports()->create(['user_id' => $resident->id, 'details' => 'Private']);
        $payload = ['status' => 'work_completed', 'assignedTo' => 'Мастер', 'nextAction' => 'Проверить результат', 'nextUpdateAt' => now()->addHour()->toIso8601String()];
        $this->actingAs($dispatcher)->patchJson("/houses/{$house->id}/incidents/{$incident->id}", $payload)->assertOk();
        Http::fake(['platform-api2.max.ru/*' => Http::response(['success' => true, 'message' => ['body' => ['mid' => 'm1']]], 200)]);
        $delivery = MaxNotification::firstOrFail();
        app(MaxDeliveryService::class)->deliver($delivery->id);
        $this->patchJson("/houses/{$house->id}/incidents/{$incident->id}", [...$payload, 'status' => 'in_progress'])->assertOk();
        $this->patchJson("/houses/{$house->id}/incidents/{$incident->id}", $payload)->assertOk();
        $this->withHeader('X-Max-Bot-Api-Secret', 'test-secret')->postJson('/api/max/webhook', ['update_type' => 'message_callback', 'callback' => ['callback_id' => 'round-1', 'user' => ['user_id' => 1234], 'payload' => 'umka:'.$delivery->token.':restored']])->assertOk();
        $this->assertDatabaseCount('incident_responses', 0);
        $this->assertDatabaseHas('max_notifications', ['kind' => 'recovery', 'round' => 2, 'status' => 'pending']);
    }

    public function test_changed_plan_supersedes_queued_status_notification(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $incident->reports()->create(['user_id' => $resident->id, 'details' => 'Private']);
        $payload = ['status' => 'in_progress', 'assignedTo' => 'Мастер', 'nextAction' => 'Выезд на место', 'nextUpdateAt' => now()->addHour()->toIso8601String()];
        $this->actingAs($dispatcher)->patchJson("/houses/{$house->id}/incidents/{$incident->id}", $payload)->assertOk();
        $this->patchJson("/houses/{$house->id}/incidents/{$incident->id}", [...$payload, 'nextAction' => 'Работа началась'])->assertOk();
        $this->assertSame(1, MaxNotification::query()->where('status', 'pending')->count());
        $this->assertSame(1, MaxNotification::query()->where('status', 'cancelled')->count());
    }

    public function test_opt_back_in_allows_a_new_poll_after_cancelled_delivery(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        $this->actingAs($resident)->putJson("/houses/{$house->id}/notifications", ['enabled' => false])->assertOk();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        $this->assertSame(1, MaxNotification::query()->where('status', 'pending')->count());
    }

    public function test_sender_preserves_concurrent_callback_proof_and_opt_out(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        $delivery = MaxNotification::firstOrFail();
        Http::fake(function () use ($delivery) {
            MaxNotification::query()->whereKey($delivery->id)->update(['status' => 'sent', 'sent_at' => now()]);

            return Http::response([], 503);
        });
        app(MaxDeliveryService::class)->deliver($delivery->id);
        $this->assertSame('sent', $delivery->fresh()->status);
        app(MaxDeliveryService::class)->deliver($delivery->id);
        Http::assertSentCount(1);
    }

    public function test_webhook_subscription_is_explicit_and_uses_a_secret(): void
    {
        $this->scenario();
        config(['services.max.webhook_url' => 'https://umka.example/api/max/webhook']);
        Http::fake(['platform-api2.max.ru/*' => Http::response(['success' => true], 200)]);
        $this->artisan('max:subscribe-webhook')->assertSuccessful();
        Http::assertSent(fn ($request) => $request['secret'] === 'test-secret' && $request['update_types'] === ['message_callback', 'bot_stopped']);
        config(['services.max.webhook_url' => 'http://umka.example/api/max/webhook']);
        $this->artisan('max:subscribe-webhook')->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_old_callback_cannot_answer_after_location_or_round_changes(): void
    {
        [$house, $dispatcher, $resident, $incident] = $this->scenario();
        $this->subscribe($house, $resident);
        $this->poll($house, $dispatcher, $incident);
        Http::fake(['platform-api2.max.ru/*' => Http::response(['success' => true, 'message' => ['body' => ['mid' => 'm1']]], 200)]);
        $delivery = MaxNotification::firstOrFail();
        app(MaxDeliveryService::class)->deliver($delivery->id);
        $house->members()->updateExistingPivot($resident->id, ['floor' => 3]);
        $event = ['update_type' => 'message_callback', 'callback' => ['callback_id' => 'old-1', 'user' => ['user_id' => 1234], 'payload' => 'umka:'.$delivery->token.':service_working']];
        $this->withHeader('X-Max-Bot-Api-Secret', 'test-secret')->postJson('/api/max/webhook', $event)->assertOk();
        $this->assertDatabaseCount('incident_responses', 0);
    }
}
