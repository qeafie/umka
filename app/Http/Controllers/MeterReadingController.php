<?php

namespace App\Http\Controllers;

use App\DTOs\MeterReadingData;
use App\Models\Meter;
use App\Models\MeterReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeterReadingController extends Controller
{
    public function store(MeterReadingData $data, Request $request, int $meterId): JsonResponse
    {
        $userId = $request->user()->id;
        $normalizedReading = str_replace(',', '.', $data->reading);

        $reading = DB::transaction(function () use ($meterId, $normalizedReading, $userId): MeterReading {
            $meter = Meter::query()
                ->whereKey($meterId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();
            $previousReading = $meter->readings()->latest('recorded_at')->first();

            if ($previousReading !== null && $this->milliUnits($normalizedReading) < $this->milliUnits($previousReading->value)) {
                throw ValidationException::withMessages([
                    'reading' => ['Новое показание не может быть меньше предыдущего.'],
                ]);
            }

            return $meter->readings()->create([
                'user_id' => $userId,
                'value' => number_format((float) $normalizedReading, 3, '.', ''),
                'recorded_at' => now(),
            ]);
        });

        return response()->json([
            'reading' => [
                'id' => $reading->id,
                'value' => $reading->value,
                'recordedAt' => $reading->recorded_at->toIso8601String(),
                'status' => 'saved_locally',
            ],
        ], 201);
    }

    private function milliUnits(string $reading): int
    {
        [$whole, $fraction] = array_pad(explode('.', $reading, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
    }
}
