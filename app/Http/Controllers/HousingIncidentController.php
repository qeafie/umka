<?php

namespace App\Http\Controllers;

use App\DTOs\HousingIncidentData;
use App\Models\House;
use App\Models\Incident;
use App\Services\IncidentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HousingIncidentController extends Controller
{
    public function houses(Request $request): JsonResponse
    {
        $houses = $request->user()->houses()
            ->orderBy('houses.address')
            ->get(['houses.id', 'houses.name', 'houses.address', 'houses.layout'])
            ->map(fn (House $house): array => [
                'id' => $house->id,
                'name' => $house->name,
                'address' => $house->address,
                'role' => $house->pivot->role,
                'layout' => $house->layout ?? [],
                'notificationsEnabled' => (bool) $house->pivot->notifications_enabled,
            ]);

        return response()->json(['houses' => $houses]);
    }

    public function index(House $house, Request $request, IncidentPresenter $presenter): JsonResponse
    {
        $membership = $house->members()
            ->whereKey($request->user()->id)
            ->first();

        abort_if($membership === null, 403);

        $incidents = $house->incidents()
            ->withCount('reports')
            ->latest('updated_at')
            ->get()
            ->map(fn (Incident $incident): array => $presenter->present(
                $incident,
                $request->user()->id,
                $membership->pivot->role === 'dispatcher',
            ));

        return response()->json(['incidents' => $incidents]);
    }

    public function store(HousingIncidentData $data, House $house, Request $request, IncidentPresenter $presenter): JsonResponse
    {
        $membership = $house->members()
            ->whereKey($request->user()->id)
            ->first();

        abort_if($membership === null, 403);

        $incident = DB::transaction(function () use ($data, $house, $request): Incident {
            if ($data->incidentId !== null) {
                $incident = $house->incidents()
                    ->whereKey($data->incidentId)
                    ->whereIn('status', ['reported', 'in_progress'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($incident->issue_type !== $data->issueType || $incident->location !== $data->location) {
                    throw ValidationException::withMessages([
                        'incidentId' => ['Можно присоединить сообщение только к проблеме того же вида и участка дома.'],
                    ]);
                }
            } else {
                $incident = $house->incidents()->create([
                    'issue_type' => $data->issueType,
                    'location' => $data->location,
                    'status' => 'reported',
                ]);
            }

            $incident->reports()->updateOrCreate(
                ['user_id' => $request->user()->id],
                ['details' => $data->details],
            );
            $incident->touch();

            return $incident->loadCount('reports');
        });

        return response()->json([
            'incident' => $presenter->present($incident, $request->user()->id, false),
        ], 201);
    }
}
