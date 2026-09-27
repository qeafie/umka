<?php

namespace App\Http\Controllers;

use App\DTOs\HousingIncidentData;
use App\Models\House;
use App\Models\Incident;
use App\Models\IncidentReport;
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
            ->get(['houses.id', 'houses.name', 'houses.address'])
            ->map(fn (House $house): array => [
                'id' => $house->id,
                'name' => $house->name,
                'address' => $house->address,
                'role' => $house->pivot->role,
            ]);

        return response()->json(['houses' => $houses]);
    }

    public function index(House $house, Request $request): JsonResponse
    {
        $membership = $house->members()
            ->whereKey($request->user()->id)
            ->first();

        abort_if($membership === null, 403);

        $incidents = $house->incidents()
            ->withCount('reports')
            ->latest('updated_at')
            ->get()
            ->map(fn (Incident $incident): array => $this->presentIncident(
                $incident,
                $membership->pivot->role === 'dispatcher',
            ));

        return response()->json(['incidents' => $incidents]);
    }

    public function store(HousingIncidentData $data, House $house, Request $request): JsonResponse
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
            'incident' => $this->presentIncident($incident, false),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function presentIncident(Incident $incident, bool $includeReports): array
    {
        $result = [
            'id' => $incident->id,
            'issueType' => $incident->issue_type,
            'location' => $incident->location,
            'status' => $incident->status,
            'reportCount' => $incident->reports_count,
            'updatedAt' => $incident->updated_at->toIso8601String(),
        ];

        if ($includeReports) {
            $result['reports'] = $incident->reports()
                ->latest()
                ->get(['id', 'details', 'created_at'])
                ->map(fn (IncidentReport $report): array => [
                    'details' => $report->details,
                    'createdAt' => $report->created_at->toIso8601String(),
                ])
                ->all();
        }

        return $result;
    }
}
