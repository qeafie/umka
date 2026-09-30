<?php

namespace App\Http\Controllers;

use App\Models\House;
use App\Models\Incident;
use App\Models\MaxNotification;
use App\Services\Max\IncidentNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HouseNotificationController extends Controller
{
    public function show(House $house, Request $request): JsonResponse
    {
        $member = $house->members()->whereKey($request->user()->id)->wherePivot('role', 'resident')->first();
        abort_if($member === null, 403);

        return response()->json(['enabled' => (bool) $member->pivot->notifications_enabled]);
    }

    public function update(House $house, Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return DB::transaction(function () use ($house, $request, $data): JsonResponse {
            $member = $house->members()->whereKey($request->user()->id)->wherePivot('role', 'resident')->lockForUpdate()->first();
            abort_if($member === null || $member->max_user_id === null, 403);
            $house->members()->updateExistingPivot($member->id, ['notifications_enabled' => $data['enabled']]);
            if (! $data['enabled']) {
                MaxNotification::query()->where('user_id', $member->id)->whereIn('incident_id', $house->incidents()->select('id'))
                    ->whereIn('status', ['pending', 'sent', 'sending', 'uncertain'])->update(['status' => 'cancelled']);
            }

            return response()->json(['enabled' => (bool) $data['enabled']]);
        });
    }

    public function summary(House $house, Incident $incident, Request $request): JsonResponse
    {
        abort_unless($house->members()->whereKey($request->user()->id)->wherePivot('role', 'dispatcher')->exists(), 403);
        abort_unless($incident->house_id === $house->id, 404);
        $counts = MaxNotification::query()->where('incident_id', $incident->id)->where('round', $incident->recovery_round)
            ->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($count): int => (int) $count);

        return response()->json(['counts' => $counts]);
    }

    public function store(House $house, Incident $incident, Request $request, IncidentNotifications $notifications): JsonResponse
    {
        abort_unless($house->members()->whereKey($request->user()->id)->wherePivot('role', 'dispatcher')->exists(), 403);
        abort_unless($incident->house_id === $house->id, 404);
        $data = $request->validate(['entrance' => ['required', 'integer', 'min:1'], 'floorFrom' => ['required', 'integer', 'min:1'], 'floorTo' => ['required', 'integer', 'gte:floorFrom']]);
        $queued = DB::transaction(function () use ($incident, $house, $data, $notifications, $request): int {
            $locked = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['reported', 'in_progress'], true)) {
                throw ValidationException::withMessages(['incident' => ['Опрос масштаба доступен до завершения работ.']]);
            }
            $floors = collect($house->fresh()->layout ?? [])->pluck('floors', 'entrance');
            if ($data['floorTo'] > $floors->get($data['entrance'], 0)) {
                throw ValidationException::withMessages(['floorTo' => ['Выберите участок из схемы дома.']]);
            }
            $queued = $notifications->scope($locked, (int) $data['entrance'], (int) $data['floorFrom'], (int) $data['floorTo']);
            $locked->activities()->create(['user_id' => $request->user()->id, 'event_type' => 'scope_survey_requested', 'payload' => [...$data, 'queued' => $queued]]);

            return $queued;
        });

        return response()->json(['queued' => $queued]);
    }
}
