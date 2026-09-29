<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class HouseLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_configures_entrances_and_assigns_a_member_location(): void
    {
        [$house, $admin, $resident] = $this->makeHouse();
        $layout = [['entrance' => 2, 'floors' => 5], ['entrance' => 1, 'floors' => 3]];

        $this->actingAs($admin)->putJson("/admin/houses/{$house->id}/layout", ['layout' => $layout])
            ->assertOk()->assertJsonPath('layout.0.entrance', 1);
        $this->putJson("/admin/houses/{$house->id}/members/{$resident->id}/location", ['entrance' => 2, 'floor' => 4])
            ->assertOk()->assertJsonPath('member.entrance', 2)->assertJsonPath('member.floor', 4);

        $this->assertSame([['entrance' => 1, 'floors' => 3], ['entrance' => 2, 'floors' => 5]], $house->fresh()->layout);
        $this->assertDatabaseHas('house_memberships', ['house_id' => $house->id, 'user_id' => $resident->id, 'entrance' => 2, 'floor' => 4, 'role' => 'resident']);
        $this->assertDatabaseHas('house_membership_activities', ['house_id' => $house->id, 'actor_id' => $admin->id, 'member_id' => $resident->id, 'event_type' => 'location_changed']);
        $this->getJson("/admin/houses/{$house->id}/members")->assertOk()
            ->assertJsonFragment(['id' => $resident->id, 'name' => $resident->name, 'role' => 'resident', 'apartment' => '24', 'entrance' => 2, 'floor' => 4]);
    }

    #[TestWith(['resident'])]
    #[TestWith(['dispatcher'])]
    #[TestWith(['moderator'])]
    public function test_non_administrators_cannot_change_layout_or_locations(string $role): void
    {
        [$house, , $resident] = $this->makeHouse();
        $house->members()->updateExistingPivot($resident->id, ['role' => $role]);

        $this->actingAs($resident)->putJson("/admin/houses/{$house->id}/layout", ['layout' => [['entrance' => 1, 'floors' => 5]]])->assertForbidden();
        $this->putJson("/admin/houses/{$house->id}/members/{$resident->id}/location", ['entrance' => 1, 'floor' => 2])->assertForbidden();

        $this->assertDatabaseCount('house_membership_activities', 0);
    }

    public function test_guest_and_another_house_administrator_cannot_change_the_house(): void
    {
        [$house, , $resident] = $this->makeHouse();
        [$otherHouse, $outsider] = $this->makeHouse();

        $this->putJson("/admin/houses/{$house->id}/layout", ['layout' => []])->assertUnauthorized();
        $this->actingAs($outsider)->putJson("/admin/houses/{$house->id}/layout", ['layout' => []])->assertForbidden();
        $this->putJson("/admin/houses/{$otherHouse->id}/members/{$resident->id}/location", ['entrance' => null, 'floor' => null])->assertNotFound();

        $this->assertDatabaseCount('house_membership_activities', 0);
    }

    #[TestWith([['layout' => [['entrance' => 1, 'floors' => 0]]], 'layout.0.floors'])]
    #[TestWith([['layout' => [['entrance' => 1, 'floors' => 61]]], 'layout.0.floors'])]
    #[TestWith([['layout' => [['entrance' => 1, 'floors' => 2], ['entrance' => 1, 'floors' => 3]]], 'layout.0.entrance'])]
    public function test_invalid_house_layout_is_rejected(array $payload, string $field): void
    {
        [$house, $admin] = $this->makeHouse();

        $this->actingAs($admin)->putJson("/admin/houses/{$house->id}/layout", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('house_membership_activities', 0);
    }

    public function test_locations_must_exist_in_the_layout_and_occupied_floors_cannot_be_removed(): void
    {
        [$house, $admin, $resident] = $this->makeHouse();
        $this->actingAs($admin)->putJson("/admin/houses/{$house->id}/layout", ['layout' => [['entrance' => 2, 'floors' => 5]]])->assertOk();

        $this->putJson("/admin/houses/{$house->id}/members/{$resident->id}/location", ['entrance' => 1, 'floor' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('entrance');
        $this->putJson("/admin/houses/{$house->id}/members/{$resident->id}/location", ['entrance' => 2, 'floor' => 6])
            ->assertUnprocessable()->assertJsonValidationErrors('floor');
        $this->putJson("/admin/houses/{$house->id}/members/{$resident->id}/location", ['entrance' => 2, 'floor' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('floor');
        $this->putJson("/admin/houses/{$house->id}/members/{$resident->id}/location", ['entrance' => 2, 'floor' => 5])->assertOk();
        $this->putJson("/admin/houses/{$house->id}/layout", ['layout' => [['entrance' => 2, 'floors' => 4]]])
            ->assertUnprocessable()->assertJsonValidationErrors('layout');

        $this->assertSame([['entrance' => 2, 'floors' => 5]], $house->fresh()->layout);
        $this->assertDatabaseCount('house_membership_activities', 2);
    }

    public function test_administrator_can_clear_location_without_removing_membership(): void
    {
        [$house, $admin, $resident] = $this->makeHouse();
        $this->actingAs($admin)->putJson("/admin/houses/{$house->id}/layout", ['layout' => [['entrance' => 1, 'floors' => 2]]])->assertOk();
        $this->putJson("/admin/houses/{$house->id}/members/{$resident->id}/location", ['entrance' => 1, 'floor' => 2])->assertOk();

        $this->putJson("/admin/houses/{$house->id}/members/{$resident->id}/location", ['entrance' => null, 'floor' => null])->assertOk();

        $this->assertDatabaseHas('house_memberships', ['house_id' => $house->id, 'user_id' => $resident->id, 'role' => 'resident', 'entrance' => null, 'floor' => null]);
    }

    /** @return array{House, User, User} */
    private function makeHouse(): array
    {
        $house = House::factory()->create();
        $admin = User::factory()->create();
        $resident = User::factory()->create();
        $house->members()->attach($admin, ['role' => 'house_admin']);
        $house->members()->attach($resident, ['role' => 'resident', 'apartment' => '24']);

        return [$house, $admin, $resident];
    }
}
