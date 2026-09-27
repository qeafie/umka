<?php

namespace App\Http\Controllers;

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

    private function authorizeAdministrator(House $house, User $user): void
    {
        $role = $house->members()->whereKey($user->id)->value('house_memberships.role');
        abort_unless($role === 'house_admin', 403);
    }
}
