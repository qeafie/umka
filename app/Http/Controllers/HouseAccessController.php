<?php

namespace App\Http\Controllers;

use App\DTOs\HouseLayoutData;
use App\DTOs\HouseMemberLocationData;
use App\DTOs\HouseMemberRoleData;
use App\Models\House;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HouseAccessController extends Controller
{
    public function index(House $house, Request $request): JsonResponse
    {
        $this->authorizeAdministrator($house, $request->user());

        $members = $house->members()->orderBy('users.name')->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->pivot->role,
                'apartment' => $user->pivot->apartment,
                'entrance' => $user->pivot->entrance,
                'floor' => $user->pivot->floor,
            ]);

        return response()->json(['members' => $members]);
    }

    public function update(HouseMemberRoleData $data, House $house, User $member, Request $request): JsonResponse
    {
        $this->authorizeAdministrator($house, $request->user());

        $result = DB::transaction(function () use ($data, $house, $member, $request): array {
            $pivot = $house->members()->whereKey($member->id)->lockForUpdate()->first();
            if ($pivot === null) {
                abort(404);
            }

            $previousRole = $pivot->pivot->role;
            $house->members()->updateExistingPivot($member->id, ['role' => $data->role]);
            $house->membershipActivities()->create([
                'actor_id' => $request->user()->id,
                'member_id' => $member->id,
                'event_type' => 'role_changed',
                'payload' => ['from' => $previousRole, 'to' => $data->role],
            ]);

            return ['id' => $member->id, 'name' => $member->name, 'role' => $data->role, 'apartment' => $pivot->pivot->apartment];
        });

        return response()->json(['member' => $result]);
    }

    public function destroy(House $house, User $member, Request $request): JsonResponse
    {
        $this->authorizeAdministrator($house, $request->user());

        if ($member->is($request->user())) {
            throw ValidationException::withMessages(['member' => ['Нельзя удалить собственный доступ администратора.']]);
        }

        DB::transaction(function () use ($house, $member, $request): void {
            $pivot = $house->members()->whereKey($member->id)->lockForUpdate()->first();
            if ($pivot === null) {
                abort(404);
            }

            $house->membershipActivities()->create([
                'actor_id' => $request->user()->id,
                'member_id' => $member->id,
                'event_type' => 'membership_removed',
                'payload' => ['role' => $pivot->pivot->role],
            ]);
            $house->members()->detach($member->id);
        });

        return response()->json([], 204);
    }

    public function updateLayout(HouseLayoutData $data, House $house, Request $request): JsonResponse
    {
        $this->authorizeAdministrator($house, $request->user());
        $layout = collect($data->layout)->map(fn (array $entrance): array => [
            'entrance' => (int) $entrance['entrance'],
            'floors' => (int) $entrance['floors'],
        ])->sortBy('entrance')->values()->all();

        DB::transaction(function () use ($house, $request, $layout): void {
            $lockedHouse = House::query()->whereKey($house->id)->lockForUpdate()->firstOrFail();
            $floors = collect($layout)->pluck('floors', 'entrance');

            foreach ($lockedHouse->members()->wherePivotNotNull('entrance')->get() as $member) {
                if ($member->pivot->floor > $floors->get($member->pivot->entrance, 0)) {
                    throw ValidationException::withMessages(['layout' => ['Сначала измените привязку жителей к удаляемому подъезду или этажу.']]);
                }
            }

            $previousLayout = $lockedHouse->layout ?? [];
            $lockedHouse->update(['layout' => $layout]);
            $lockedHouse->membershipActivities()->create([
                'actor_id' => $request->user()->id,
                'member_id' => $request->user()->id,
                'event_type' => 'layout_changed',
                'payload' => ['from' => $previousLayout, 'to' => $layout],
            ]);
        });

        return response()->json(['layout' => $layout]);
    }

    public function updateLocation(HouseMemberLocationData $data, House $house, User $member, Request $request): JsonResponse
    {
        $this->authorizeAdministrator($house, $request->user());

        $location = DB::transaction(function () use ($data, $house, $member, $request): array {
            $lockedHouse = House::query()->whereKey($house->id)->lockForUpdate()->firstOrFail();
            $membership = $lockedHouse->members()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $floors = collect($lockedHouse->layout ?? [])->pluck('floors', 'entrance');

            if ($data->entrance !== null && ! $floors->has($data->entrance)) {
                throw ValidationException::withMessages(['entrance' => ['Выберите подъезд из схемы дома.']]);
            }
            if ($data->floor !== null && $data->floor > $floors->get($data->entrance, 0)) {
                throw ValidationException::withMessages(['floor' => ['Такого этажа нет в выбранном подъезде.']]);
            }

            $location = ['entrance' => $data->entrance, 'floor' => $data->floor];
            $lockedHouse->members()->updateExistingPivot($member->id, $location);
            $lockedHouse->membershipActivities()->create([
                'actor_id' => $request->user()->id,
                'member_id' => $member->id,
                'event_type' => 'location_changed',
                'payload' => [
                    'from' => ['entrance' => $membership->pivot->entrance, 'floor' => $membership->pivot->floor],
                    'to' => $location,
                ],
            ]);

            return ['id' => $member->id, ...$location];
        });

        return response()->json(['member' => $location]);
    }

    private function authorizeAdministrator(House $house, User $user): void
    {
        $role = $house->members()->whereKey($user->id)->value('house_memberships.role');
        abort_unless($role === 'house_admin', 403);
    }
}
