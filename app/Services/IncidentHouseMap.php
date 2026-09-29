<?php

namespace App\Services;

use App\Models\Incident;

class IncidentHouseMap
{
    /** @return array{stage: string, areas: array<int, array<string, mixed>>, unlocated: array<string, mixed>} */
    public function present(Incident $incident): array
    {
        $house = $incident->house;
        $stage = $incident->status === 'work_completed' ? 'recovery' : 'scope';
        $round = $stage === 'recovery' ? $incident->recovery_round : 0;
        $cutoff = now()->subHours(max(1, (int) config('incidents.response_freshness_hours', 24)));
        $members = $house->members()->wherePivot('role', 'resident');

        if ($stage === 'recovery') {
            $members->whereIn('users.id', $incident->reports()->select('user_id'));
        }

        $responses = $incident->responses()->where('stage', $stage)->where('round', $round)
            ->get(['user_id', 'answer', 'updated_at', 'entrance', 'floor'])->keyBy('user_id');
        $areas = [];
        foreach ($house->layout ?? [] as $entrance) {
            for ($floor = 1; $floor <= $entrance['floors']; $floor++) {
                $areas[$entrance['entrance'].':'.$floor] = $this->emptyArea((int) $entrance['entrance'], $floor);
            }
        }
        $areas['unlocated'] = $this->emptyArea(null, null);

        foreach ($members->get(['users.id']) as $member) {
            $key = $member->pivot->entrance.':'.$member->pivot->floor;
            if (! isset($areas[$key])) {
                $key = 'unlocated';
            }
            $areas[$key]['participants']++;
            $response = $responses->get($member->id);

            if ($response !== null) {
                $checkedAt = $response->updated_at->toIso8601String();
                $areas[$key]['lastCheckedAt'] = max($areas[$key]['lastCheckedAt'] ?? $checkedAt, $checkedAt);
            }
            $locationChanged = $response !== null
                && ($response->entrance != $member->pivot->entrance || $response->floor != $member->pivot->floor);
            if ($response === null || $response->updated_at->lt($cutoff) || $locationChanged) {
                $areas[$key]['noResponse']++;
                $areas[$key]['stale'] += $response === null ? 0 : 1;

                continue;
            }

            $countKey = match ($response->answer) {
                'problem_present', 'problem_remains' => 'problem',
                'service_working', 'restored' => 'working',
                default => 'cannotCheck',
            };
            $areas[$key][$countKey]++;
        }

        foreach ($areas as &$area) {
            $area['status'] = match (true) {
                $area['problem'] > 0 && $area['working'] > 0 => 'mixed',
                $area['problem'] > 0 => 'problem',
                $area['working'] > 0 && $area['working'] === $area['participants'] => 'confirmed',
                $area['working'] > 0 => 'partial',
                default => 'unknown',
            };
        }
        unset($area);
        $unlocated = $areas['unlocated'];
        unset($areas['unlocated']);

        return ['stage' => $stage, 'areas' => array_values($areas), 'unlocated' => $unlocated];
    }

    /** @return array<string, mixed> */
    private function emptyArea(?int $entrance, ?int $floor): array
    {
        return [
            'entrance' => $entrance,
            'floor' => $floor,
            'participants' => 0,
            'problem' => 0,
            'working' => 0,
            'cannotCheck' => 0,
            'noResponse' => 0,
            'stale' => 0,
            'lastCheckedAt' => null,
        ];
    }
}
