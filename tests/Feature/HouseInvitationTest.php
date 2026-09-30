<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\HouseInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HouseInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function house(): array
    {
        $house = House::factory()->create(['layout' => [['entrance' => 2, 'floors' => 5]]]);
        $admin = User::factory()->create();
        $house->members()->attach($admin, ['role' => 'house_admin']);

        return [$house, $admin];
    }

    private function invite(House $house, User $admin): array
    {
        return $this->actingAs($admin)->postJson("/admin/houses/{$house->id}/invitations", [
            'apartment' => '24', 'entrance' => 2, 'floor' => 4,
        ])->assertCreated()->json();
    }

    public function test_invitation_grants_resident_access_once_without_storing_the_secret(): void
    {
        [$house, $admin] = $this->house();
        $invitation = $this->invite($house, $admin);
        $this->assertDatabaseHas('house_invitations', ['token_hash' => hash('sha256', $invitation['token'])]);
        $this->assertStringNotContainsString($invitation['token'], json_encode(DB::table('house_invitations')->first()));
        $resident = User::factory()->create(['max_user_id' => '1234']);
        $this->actingAs($resident)->postJson('/invitations/preview', ['token' => $invitation['token']])
            ->assertOk()->assertJsonPath('invitation.house.name', $house->name);
        $this->postJson('/invitations/accept', ['token' => $invitation['token'], 'role' => 'house_admin'])
            ->assertCreated()->assertJsonPath('house.id', $house->id);
        $this->assertDatabaseHas('house_memberships', ['house_id' => $house->id, 'user_id' => $resident->id, 'role' => 'resident', 'apartment' => '24', 'entrance' => 2, 'floor' => 4]);
        $this->assertDatabaseHas('house_membership_activities', ['event_type' => 'invitation_accepted', 'member_id' => $resident->id]);
        $other = User::factory()->create(['max_user_id' => '5678']);
        $this->actingAs($other)->postJson('/invitations/accept', ['token' => $invitation['token']])->assertUnprocessable();
        $this->assertDatabaseCount('house_memberships', 2);
    }

    public function test_only_administrators_of_this_house_can_manage_invitations(): void
    {
        [$house, $admin] = $this->house();
        $invitation = $this->invite($house, $admin);
        [$otherHouse, $otherAdmin] = $this->house();
        $this->actingAs($otherAdmin)->getJson("/admin/houses/{$house->id}/invitations")->assertForbidden();
        $this->postJson("/admin/houses/{$house->id}/invitations", ['apartment' => '24'])->assertForbidden();
        $this->deleteJson("/admin/houses/{$otherHouse->id}/invitations/{$invitation['invitation']['id']}")->assertNotFound();
        $this->actingAs($admin)->getJson("/admin/houses/{$house->id}/invitations")->assertOk()
            ->assertJsonMissingPath('invitations.0.token')->assertJsonMissingPath('invitations.0.token_hash');
    }

    public function test_expired_revoked_and_removed_issuer_invitations_cannot_be_used(): void
    {
        [$house, $admin] = $this->house();
        $resident = User::factory()->create(['max_user_id' => '1234']);
        $expired = $this->invite($house, $admin);
        $this->travel(73)->hours();
        $this->actingAs($resident)->postJson('/invitations/accept', ['token' => $expired['token']])->assertUnprocessable();
        $revoked = $this->invite($house, $admin);
        $this->deleteJson("/admin/houses/{$house->id}/invitations/{$revoked['invitation']['id']}")->assertNoContent();
        $this->actingAs($resident)->postJson('/invitations/accept', ['token' => $revoked['token']])->assertUnprocessable();
        $removed = $this->invite($house, $admin);
        $house->members()->detach($admin);
        $this->actingAs($resident)->postJson('/invitations/accept', ['token' => $removed['token']])->assertUnprocessable();
        $this->assertDatabaseCount('house_memberships', 0);
    }

    public function test_existing_membership_is_not_overwritten_or_invitation_consumed(): void
    {
        [$house, $admin] = $this->house();
        $invitation = $this->invite($house, $admin);
        $admin->update(['max_user_id' => '1234']);
        $this->postJson('/invitations/accept', ['token' => $invitation['token']])->assertConflict();
        $this->assertDatabaseHas('house_memberships', ['user_id' => $admin->id, 'role' => 'house_admin']);
        $this->assertDatabaseHas('house_invitations', ['id' => $invitation['invitation']['id'], 'accepted_at' => null]);
    }

    public function test_location_is_validated_again_at_acceptance(): void
    {
        [$house, $admin] = $this->house();
        $this->actingAs($admin)->postJson("/admin/houses/{$house->id}/invitations", ['apartment' => '24', 'entrance' => 2, 'floor' => 6])->assertUnprocessable();
        $invitation = $this->invite($house, $admin);
        $house->update(['layout' => []]);
        $resident = User::factory()->create(['max_user_id' => '1234']);
        $this->actingAs($resident)->postJson('/invitations/accept', ['token' => $invitation['token']])->assertUnprocessable();
        $this->assertDatabaseCount('house_memberships', 1);
    }

    public function test_used_invitation_cannot_report_successful_revocation(): void
    {
        [$house, $admin] = $this->house();
        $invitation = $this->invite($house, $admin);
        $this->actingAs(User::factory()->create(['max_user_id' => '1234']))->postJson('/invitations/accept', ['token' => $invitation['token']])->assertCreated();
        $this->actingAs($admin)->deleteJson("/admin/houses/{$house->id}/invitations/{$invitation['invitation']['id']}")->assertConflict();
        $this->assertDatabaseHas('house_invitations', ['id' => $invitation['invitation']['id'], 'revoked_at' => null]);
    }

    public function test_older_pending_invitations_remain_available_for_revocation(): void
    {
        [$house, $admin] = $this->house();
        $invitation = $this->invite($house, $admin);
        HouseInvitation::factory()->count(100)->create(['house_id' => $house->id, 'created_by' => $admin->id]);
        $this->getJson("/admin/houses/{$house->id}/invitations")->assertOk()->assertJsonFragment(['id' => $invitation['invitation']['id']])->assertJsonCount(101, 'invitations');
    }

    public function test_guest_or_unverified_user_cannot_accept_and_invalid_token_does_not_leak_data(): void
    {
        $this->postJson('/invitations/accept', ['token' => str_repeat('a', 64)])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/invitations/accept', ['token' => str_repeat('a', 64)])->assertForbidden();
        $this->actingAs(User::factory()->create(['max_user_id' => '1234']))->postJson('/invitations/preview', ['token' => str_repeat('a', 64)])->assertUnprocessable();
    }
}
