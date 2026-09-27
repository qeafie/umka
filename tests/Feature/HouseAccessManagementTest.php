<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
