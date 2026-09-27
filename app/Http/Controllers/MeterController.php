<?php

namespace App\Http\Controllers;

use App\DTOs\MeterData;
use App\Models\Meter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $meters = $request->user()->meters()->orderBy('created_at')->get()->map(function (Meter $meter): array {
            $service = config('meters.services.'.$meter->service);

            return [
                'id' => $meter->id,
                'name' => $meter->name,
                'service' => $meter->service,
                'serviceLabel' => $service['label'],
                'unit' => $service['unit'],
                'serialNumber' => $meter->serial_number,
                'readings' => $meter->readings()
                    ->latest('recorded_at')
                    ->limit(5)
                    ->get()
                    ->map(fn ($reading): array => [
                        'value' => $reading->value,
                        'recordedAt' => $reading->recorded_at->toIso8601String(),
                    ])
                    ->values(),
            ];
        });

        return response()->json(['meters' => $meters]);
    }

    public function store(MeterData $data, Request $request): JsonResponse
    {
        $meter = DB::transaction(fn (): Meter => $request->user()->meters()->create([
            'name' => $data->name,
            'service' => $data->service,
            'serial_number' => $data->serialNumber,
        ]));
        $service = config('meters.services.'.$meter->service);

        return response()->json([
            'meter' => [
                'id' => $meter->id,
                'name' => $meter->name,
                'service' => $meter->service,
                'serviceLabel' => $service['label'],
                'unit' => $service['unit'],
                'serialNumber' => $meter->serial_number,
                'readings' => [],
            ],
        ], 201);
    }
}
