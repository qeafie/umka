<?php

namespace App\Services;

use App\Models\House;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncidentResponses
{
    public function record(House $house, Incident $incident, User $user, string $stage, string $answer, ?int $expectedRound = null): array
    {
        abort_unless($incident->house_id === $house->id, 404);
        if (! in_array($stage, ['scope', 'recovery'], true)) {
            throw ValidationException::withMessages(['stage' => ['Неизвестный этап опроса.']]);
        }

        return DB::transaction(function () use ($stage, $answer, $house, $incident, $user, $expectedRound): array {
            $lockedIncident = $house->incidents()
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->firstOrFail();

            $membership = $house->members()->whereKey($user->id)->lockForUpdate()->first();
            abort_if($membership === null || $membership->pivot->role !== 'resident', 403);
            if ($expectedRound !== null && (int) $lockedIncident->recovery_round !== $expectedRound) {
                throw ValidationException::withMessages(['stage' => ['Этот опрос уже завершён.']]);
            }

            if ($stage === 'scope') {
                if (! in_array($lockedIncident->status, ['reported', 'in_progress'], true)) {
                    throw ValidationException::withMessages([
                        'stage' => ['Проверка масштаба доступна, пока проблема не отмечена выполненной.'],
                    ]);
                }

                if (! in_array($answer, ['problem_present', 'service_working', 'cannot_check'], true)) {
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

                if (! in_array($answer, ['restored', 'problem_remains', 'cannot_check'], true)) {
                    throw ValidationException::withMessages([
                        'answer' => ['Выберите ответ о результате работ.'],
                    ]);
                }

                if (! $lockedIncident->reports()->where('user_id', $user->id)->exists()) {
                    abort(403);
                }

                $round = $lockedIncident->recovery_round;
            }

            $existingResponse = $lockedIncident->responses()
                ->where('user_id', $user->id)
                ->where('stage', $stage)
                ->where('round', $round)
                ->first();
            $answerChanged = $existingResponse?->answer !== $answer;

            $savedResponse = $lockedIncident->responses()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'stage' => $stage,
                    'round' => $round,
                ],
                ['answer' => $answer, 'entrance' => $membership->pivot->entrance, 'floor' => $membership->pivot->floor],
            );

            if (! $answerChanged) {
                $savedResponse->touch();
            }

            if ($answerChanged) {
                $lockedIncident->activities()->create([
                    'user_id' => $user->id,
                    'event_type' => $stage === 'scope' ? 'scope_response' : 'recovery_response',
                    'payload' => ['stage' => $stage, 'round' => $round, 'answer' => $answer],
                ]);
            }

            return [$savedResponse, $existingResponse === null];
        });

    }
}
