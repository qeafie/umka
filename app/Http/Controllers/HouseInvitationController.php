<?php

namespace App\Http\Controllers;

use App\DTOs\HouseInvitationData;
use App\Models\House;
use App\Models\HouseInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HouseInvitationController extends Controller
{
    public function index(House $house, Request $request): JsonResponse
    {
        $this->authorizeAdministrator($house, $request->user());

        return response()->json(['invitations' => HouseInvitation::query()->where('house_id', $house->id)->latest('id')->get()->map($this->present(...))]);
    }

    public function store(HouseInvitationData $data, House $house, Request $request): JsonResponse
    {
        $this->authorizeAdministrator($house, $request->user());
        $token = bin2hex(random_bytes(32));
        $invitation = DB::transaction(function () use ($data, $house, $request, $token): HouseInvitation {
            $house = House::query()->whereKey($house->id)->lockForUpdate()->firstOrFail();
            $this->authorizeAdministrator($house, $request->user());
            $this->validateLocation($house, $data->entrance, $data->floor);
            $invitation = HouseInvitation::query()->create([
                'house_id' => $house->id, 'created_by' => $request->user()->id,
                'token_hash' => hash('sha256', $token), 'apartment' => $data->apartment,
                'entrance' => $data->entrance, 'floor' => $data->floor, 'expires_at' => now()->addHours(72),
            ]);
            $this->audit($house, $request->user(), $request->user(), 'invitation_created', $invitation);

            return $invitation;
        });
        $bot = config('services.max.bot_username');

        return response()->json([
            'invitation' => $this->present($invitation), 'token' => $token,
            'url' => is_string($bot) && preg_match('/^[a-zA-Z0-9_]+$/', $bot) ? 'https://max.ru/'.$bot.'?startapp=invite_'.$token : null,
        ], 201);
    }

    public function destroy(House $house, HouseInvitation $invitation, Request $request): JsonResponse
    {
        $this->authorizeAdministrator($house, $request->user());
        abort_unless($invitation->house_id === $house->id, 404);
        DB::transaction(function () use ($house, $invitation, $request): void {
            $house = House::query()->whereKey($house->id)->lockForUpdate()->firstOrFail();
            $invitation = HouseInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $this->authorizeAdministrator($house, $request->user());
            abort_if($invitation->accepted_at !== null, 409, 'Приглашение уже принято. Для отзыва доступа откройте список участников.');
            if ($invitation->revoked_at === null) {
                $invitation->update(['revoked_at' => now()]);
                $this->audit($house, $request->user(), $request->user(), 'invitation_revoked', $invitation);
            }
        });

        return response()->json([], 204);
    }

    public function preview(Request $request): JsonResponse
    {
        $invitation = $this->findInvitation($request);
        $house = House::query()->findOrFail($invitation->house_id);
        $this->validateInvitation($invitation, $house);

        return response()->json(['invitation' => [...$this->present($invitation), 'house' => $house->only(['id', 'name', 'address'])]]);
    }

    public function accept(Request $request): JsonResponse
    {
        $candidate = $this->findInvitation($request);
        $house = DB::transaction(function () use ($candidate, $request): House {
            $house = House::query()->whereKey($candidate->house_id)->lockForUpdate()->firstOrFail();
            $invitation = HouseInvitation::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $this->validateInvitation($invitation, $house);
            $this->validateLocation($house, $invitation->entrance, $invitation->floor);
            abort_if($house->members()->whereKey($request->user()->id)->exists(), 409, 'Вы уже участник этого дома. Ваши права не изменены.');
            $house->members()->attach($request->user(), ['role' => 'resident', 'apartment' => $invitation->apartment, 'entrance' => $invitation->entrance, 'floor' => $invitation->floor]);
            $invitation->update(['accepted_at' => now(), 'accepted_by' => $request->user()->id]);
            $this->audit($house, $request->user(), $request->user(), 'invitation_accepted', $invitation);

            return $house;
        });

        return response()->json(['house' => $house->only(['id', 'name', 'address'])], 201);
    }

    private function findInvitation(Request $request): HouseInvitation
    {
        abort_if($request->user()->max_user_id === null, 403, 'Войдите через MAX, чтобы принять приглашение.');
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        $invitation = HouseInvitation::query()->where('token_hash', hash('sha256', $data['token']))->first();
        if ($invitation === null) {
            throw ValidationException::withMessages(['token' => ['Приглашение недействительно. Попросите администратора выдать новое.']]);
        }

        return $invitation;
    }

    private function validateInvitation(HouseInvitation $invitation, House $house): void
    {
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null || $invitation->expires_at->lte(now()) || ! $house->members()->whereKey($invitation->created_by)->wherePivot('role', 'house_admin')->exists()) {
            throw ValidationException::withMessages(['token' => ['Приглашение использовано, отозвано или истекло. Попросите новое.']]);
        }
    }

    private function validateLocation(House $house, ?int $entrance, ?int $floor): void
    {
        if ($entrance !== null && ($floor === null || $floor > collect($house->layout ?? [])->pluck('floors', 'entrance')->get($entrance, 0))) {
            throw ValidationException::withMessages(['floor' => ['Выберите существующий подъезд и этаж. Если схема изменилась, создайте новое приглашение.']]);
        }
    }

    private function authorizeAdministrator(House $house, User $user): void
    {
        abort_unless($house->members()->whereKey($user->id)->wherePivot('role', 'house_admin')->exists(), 403);
    }

    private function present(HouseInvitation $invitation): array
    {
        return [
            'id' => $invitation->id, 'apartment' => $invitation->apartment, 'entrance' => $invitation->entrance, 'floor' => $invitation->floor,
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'status' => $invitation->accepted_at ? 'accepted' : ($invitation->revoked_at ? 'revoked' : ($invitation->expires_at->lte(now()) ? 'expired' : 'pending')),
        ];
    }

    private function audit(House $house, User $actor, User $member, string $event, HouseInvitation $invitation): void
    {
        $house->membershipActivities()->create(['actor_id' => $actor->id, 'member_id' => $member->id, 'event_type' => $event, 'payload' => ['invitationId' => $invitation->id, 'role' => 'resident', 'apartment' => $invitation->apartment, 'entrance' => $invitation->entrance, 'floor' => $invitation->floor]]);
    }
}
