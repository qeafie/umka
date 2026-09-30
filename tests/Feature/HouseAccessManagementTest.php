<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class HouseAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_house_administrator_can_list_members_and_change_their_house_role(): void
    {
        [$house, $administrator, $resident, $dispatcher] = $this->makeHouse();

        $this->actingAs($administrator)
            ->getJson("/admin/houses/{$house->id}/members")
            ->assertOk()
            ->assertJsonCount(3, 'members');

        $this->actingAs($administrator)
            ->putJson("/admin/houses/{$house->id}/members/{$resident->id}", ['role' => 'dispatcher'])
            ->assertOk()
            ->assertJsonPath('member.role', 'dispatcher');

        $this->assertDatabaseHas('house_memberships', [
            'house_id' => $house->id,
            'user_id' => $resident->id,
            'role' => 'dispatcher',
        ]);
        $this->assertDatabaseHas('house_membership_activities', [
            'house_id' => $house->id,
            'actor_id' => $administrator->id,
            'member_id' => $resident->id,
            'event_type' => 'role_changed',
        ]);
    }

    public function test_non_administrator_cannot_manage_house_access(): void
    {
        [$house, , , $dispatcher] = $this->makeHouse();

        $this->actingAs($dispatcher)
            ->getJson("/admin/houses/{$house->id}/members")
            ->assertForbidden();
    }

    public function test_house_administrator_cannot_grant_administrator_or_unknown_roles(): void
    {
        [$house, $administrator, $resident] = $this->makeHouse();

        $this->actingAs($administrator)
            ->putJson("/admin/houses/{$house->id}/members/{$resident->id}", ['role' => 'administrator'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('house_memberships', [
            'house_id' => $house->id,
            'user_id' => $resident->id,
            'role' => 'resident',
        ]);
    }

    public function test_house_administrator_can_remove_a_member_but_not_themselves(): void
    {
        [$house, $administrator, $resident] = $this->makeHouse();

        $this->actingAs($administrator)
            ->deleteJson("/admin/houses/{$house->id}/members/{$resident->id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('house_memberships', ['house_id' => $house->id, 'user_id' => $resident->id]);

        $this->actingAs($administrator)
            ->deleteJson("/admin/houses/{$house->id}/members/{$administrator->id}")
            ->assertUnprocessable();
    }

    public function test_member_list_identifies_the_current_administrator_without_exposing_credentials(): void
    {
        [$house, $administrator, $resident] = $this->makeHouse();

        $response = $this->actingAs($administrator)->getJson("/admin/houses/{$house->id}/members")->assertOk();
        $members = collect($response->json('members'))->keyBy('id');

        $this->assertTrue($members[$administrator->id]['isCurrentUser']);
        $this->assertFalse($members[$resident->id]['isCurrentUser']);
        $response->assertJsonMissingPath('members.0.email')->assertJsonMissingPath('members.0.password');
    }

    public function test_administrator_cannot_demote_themselves_and_lose_access(): void
    {
        [$house, $administrator] = $this->makeHouse();

        $this->actingAs($administrator)
            ->putJson("/admin/houses/{$house->id}/members/{$administrator->id}", ['role' => 'resident'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('member');

        $this->assertDatabaseHas('house_memberships', [
            'house_id' => $house->id, 'user_id' => $administrator->id, 'role' => 'house_admin',
        ]);
        $this->assertDatabaseCount('house_membership_activities', 0);
    }

    #[TestWith(['resident'])]
    #[TestWith(['dispatcher'])]
    #[TestWith(['moderator'])]
    public function test_non_administrators_cannot_change_roles_or_revoke_access(string $role): void
    {
        [$house, , $resident, $actor] = $this->makeHouse();
        $house->members()->updateExistingPivot($actor->id, ['role' => $role]);

        $this->actingAs($actor)->putJson("/admin/houses/{$house->id}/members/{$resident->id}", ['role' => 'moderator'])->assertForbidden();
        $this->deleteJson("/admin/houses/{$house->id}/members/{$resident->id}")->assertForbidden();

        $this->assertDatabaseHas('house_memberships', [
            'house_id' => $house->id, 'user_id' => $resident->id, 'role' => 'resident',
        ]);
        $this->assertDatabaseCount('house_membership_activities', 0);
    }

    public function test_membership_changes_are_isolated_to_the_administrators_house(): void
    {
        [$house, $administrator, $resident] = $this->makeHouse();
        [$otherHouse, , $otherResident] = $this->makeHouse();

        $this->actingAs($administrator)->getJson("/admin/houses/{$otherHouse->id}/members")->assertForbidden();
        $this->putJson("/admin/houses/{$otherHouse->id}/members/{$otherResident->id}", ['role' => 'moderator'])->assertForbidden();
        $this->deleteJson("/admin/houses/{$otherHouse->id}/members/{$otherResident->id}")->assertForbidden();
        $this->putJson("/admin/houses/{$house->id}/members/{$otherResident->id}", ['role' => 'moderator'])->assertNotFound();
        $this->deleteJson("/admin/houses/{$house->id}/members/{$otherResident->id}")->assertNotFound();

        $otherHouse->members()->attach($resident, ['role' => 'dispatcher']);
        $this->putJson("/admin/houses/{$house->id}/members/{$resident->id}", ['role' => 'moderator'])->assertOk();
        $this->deleteJson("/admin/houses/{$house->id}/members/{$resident->id}")->assertNoContent();
        $this->assertDatabaseHas('house_memberships', [
            'house_id' => $otherHouse->id, 'user_id' => $resident->id, 'role' => 'dispatcher',
        ]);
        $this->assertDatabaseHas('house_memberships', [
            'house_id' => $otherHouse->id, 'user_id' => $otherResident->id, 'role' => 'resident',
        ]);
        $this->assertDatabaseHas('house_membership_activities', [
            'house_id' => $house->id, 'actor_id' => $administrator->id,
            'member_id' => $resident->id, 'event_type' => 'membership_removed',
        ]);
        $this->assertDatabaseCount('house_membership_activities', 2);
    }

    public function test_guests_cannot_list_or_manage_members(): void
    {
        [$house, , $resident] = $this->makeHouse();

        $this->getJson("/admin/houses/{$house->id}/members")->assertUnauthorized();
        $this->putJson("/admin/houses/{$house->id}/members/{$resident->id}", ['role' => 'dispatcher'])->assertUnauthorized();
        $this->deleteJson("/admin/houses/{$house->id}/members/{$resident->id}")->assertUnauthorized();

        $this->assertDatabaseCount('house_memberships', 3);
        $this->assertDatabaseCount('house_membership_activities', 0);
    }

    /** @return array{House, User, User, User} */
    private function makeHouse(): array
    {
        $house = House::factory()->create();
        $administrator = User::factory()->create();
        $resident = User::factory()->create();
        $dispatcher = User::factory()->create();
        $house->members()->attach($administrator, ['role' => 'house_admin']);
        $house->members()->attach($resident, ['role' => 'resident']);
        $house->members()->attach($dispatcher, ['role' => 'dispatcher']);

        return [$house, $administrator, $resident, $dispatcher];
    }
}
