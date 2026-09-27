<?php

namespace App\Http\Controllers;

use App\DTOs\IncidentResponseData;
use App\DTOs\IncidentWorkflowUpdateData;
use App\Models\House;
use App\Models\Incident;
use App\Services\IncidentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncidentWorkflowController extends Controller
{
    public function update(IncidentWorkflowUpdateData $data, House $house, Incident $incident, Request $request, IncidentPresenter $presenter): JsonResponse
    {
        $membership = $house->members()
            ->whereKey($request->user()->id)
            ->wherePivot('role', 'dispatcher')
            ->first();

        abort_if($membership === null, 403);
        abort_unless($house->incidents()->whereKey($incident->id)->exists(), 404);

        $updatedIncident = DB::transaction(function () use ($data, $house, $incident, $request): Incident {
            $lockedIncident = $house->incidents()
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentStatus = $lockedIncident->status;
            $targetStatus = $data->status;
            $allowedTransitions = [
                'reported' => ['in_progress'],
                'in_progress' => ['in_progress', 'work_completed'],
                'work_completed' => ['in_progress', 'work_completed'],
            ];

            if ($targetStatus !== $currentStatus && ! in_array($targetStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Этот переход статуса недоступен. Обновите карточку и повторите действие.'],
                ]);
            }

            $lockedIncident->assigned_to = $data->assignedTo;
            $lockedIncident->next_action = $data->nextAction;
            $lockedIncident->next_update_at = $data->nextUpdateAt;

            if ($targetStatus === 'work_completed' && $currentStatus !== 'work_completed') {
                $lockedIncident->work_completed_at = now();
                $lockedIncident->recovery_round++;
            } elseif ($targetStatus === 'in_progress' && $currentStatus === 'work_completed') {
                $lockedIncident->work_completed_at = null;
            }

            $lockedIncident->status = $targetStatus;
            $lockedIncident->save();

            if ($targetStatus === 'work_completed' && $currentStatus !== 'work_completed') {
                $eventType = 'work_completed';
            } elseif ($targetStatus === 'in_progress' && $currentStatus === 'work_completed') {
                $eventType = 'work_reopened';
            } else {
                $eventType = 'work_updated';
            }

            $lockedIncident->activities()->create([
                'user_id' => $request->user()->id,
                'event_type' => $eventType,
                'payload' => [
                    'status' => $targetStatus,
                    'assignedTo' => $data->assignedTo,
                    'nextAction' => $data->nextAction,
                    'nextUpdateAt' => $data->nextUpdateAt,
                ],
            ]);

            return $lockedIncident->loadCount('reports');
        });

        return response()->json([
            'incident' => $presenter->present($updatedIncident, $request->user()->id, true),
        ]);
    }

    public function respond(IncidentResponseData $data, House $house, Incident $incident, Request $request): JsonResponse
    {
        $membership = $house->members()
            ->whereKey($request->user()->id)
            ->first();

        abort_if($membership === null || $membership->pivot->role !== 'resident', 403);
        abort_unless($house->incidents()->whereKey($incident->id)->exists(), 404);

        $response = DB::transaction(function () use ($data, $house, $incident, $request): array {
            $lockedIncident = $house->incidents()
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($data->stage === 'scope') {
                if (! in_array($lockedIncident->status, ['reported', 'in_progress'], true)) {
                    throw ValidationException::withMessages([
                        'stage' => ['Проверка масштаба доступна, пока проблема не отмечена выполненной.'],
                    ]);
                }

                if (! in_array($data->answer, ['problem_present', 'service_working', 'cannot_check'], true)) {
                    throw ValidationException::withMessages([
                        'answer' => ['Выберите ответ о текущем состоянии услуги.'],
                    ]);
                }

                $round = 0;
            } else {
                if ($lockedIncident->status !== 'work_completed') {
                    throw ValidationException::withMessages([
                        'stage' => ['Проверка восстановления появится после отметки о завершении работ.'],
                    ]);
                }

                if (! in_array($data->answer, ['restored', 'problem_remains', 'cannot_check'], true)) {
                    throw ValidationException::withMessages([
                        'answer' => ['Выберите ответ о результате работ.'],
                    ]);
                }

                if (! $lockedIncident->reports()->where('user_id', $request->user()->id)->exists()) {
                    abort(403);
                }

                $round = $lockedIncident->recovery_round;
            }

            $existingResponse = $lockedIncident->responses()
                ->where('user_id', $request->user()->id)
                ->where('stage', $data->stage)
                ->where('round', $round)
                ->first();
            $answerChanged = $existingResponse?->answer !== $data->answer;

            $savedResponse = $lockedIncident->responses()->updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'stage' => $data->stage,
                    'round' => $round,
                ],
                ['answer' => $data->answer],
            );

            if ($answerChanged) {
                $lockedIncident->activities()->create([
                    'user_id' => $request->user()->id,
                    'event_type' => $data->stage === 'scope' ? 'scope_response' : 'recovery_response',
                    'payload' => ['stage' => $data->stage, 'round' => $round, 'answer' => $data->answer],
                ]);
            }

            return [$savedResponse, $existingResponse === null];
        });

        [$savedResponse, $created] = $response;

        return response()->json([
            'response' => [
                'stage' => $savedResponse->stage,
                'answer' => $savedResponse->answer,
                'round' => $savedResponse->round,
                'createdAt' => $savedResponse->created_at->toIso8601String(),
                'updatedAt' => $savedResponse->updated_at->toIso8601String(),
            ],
        ], $created ? 201 : 200);
    }
}
