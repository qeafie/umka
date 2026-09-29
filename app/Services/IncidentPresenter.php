<?php

namespace App\Services;

use App\Models\Incident;
use Illuminate\Support\Carbon;

class IncidentPresenter
{
    public function __construct(private IncidentHouseMap $houseMap) {}

    /** @return array<string, mixed> */
    public function present(Incident $incident, int $userId, bool $includeReports): array
    {
        $reportCount = (int) ($incident->reports_count ?? $incident->reports()->count());
        $scopeCounts = $this->responseCounts($incident, 'scope', 0);
        $scopeTotal = array_sum($scopeCounts) - $scopeCounts['stale'];
        $myScopeQuery = $incident->responses()
            ->where('user_id', $userId)
            ->where('stage', 'scope')
            ->where('round', 0);
        $myScopeResponse = (clone $myScopeQuery)->value('answer');
        $myScopeResponseIsFresh = (clone $myScopeQuery)->where('updated_at', '>=', $this->freshnessCutoff())->exists();

        $result = [
            'id' => $incident->id,
            'issueType' => $incident->issue_type,
            'location' => $incident->location,
            'status' => $incident->status,
            'reportCount' => $reportCount,
            'assignedTo' => $incident->assigned_to,
            'nextAction' => $incident->next_action,
            'nextUpdateAt' => $incident->next_update_at?->toIso8601String(),
            'workCompletedAt' => $incident->work_completed_at?->toIso8601String(),
            'updatedAt' => $incident->updated_at->toIso8601String(),
            'scopeResponses' => [
                'problemPresent' => $scopeCounts['problem_present'],
                'serviceWorking' => $scopeCounts['service_working'],
                'cannotCheck' => $scopeCounts['cannot_check'],
                'total' => $scopeTotal,
                'staleResponses' => $scopeCounts['stale'],
            ],
            'myScopeResponse' => $myScopeResponse,
            'myScopeResponseIsFresh' => $myScopeResponseIsFresh,
            'responseFreshnessHours' => $this->freshnessHours(),
            'recovery' => null,
            'myRecoveryResponse' => null,
            'canConfirmRecovery' => false,
            'houseMap' => $this->houseMap->present($incident),
        ];

        if ($incident->status === 'work_completed') {
            $round = (int) $incident->recovery_round;
            $recoveryCounts = $this->responseCounts($incident, 'recovery', $round);
            $recoveryResponseCount = array_sum($recoveryCounts) - $recoveryCounts['stale'];
            $isReporter = $incident->reports()->where('user_id', $userId)->exists();

            $result['recovery'] = [
                'round' => $round,
                'totalReports' => $reportCount,
                'restored' => $recoveryCounts['restored'],
                'problemRemains' => $recoveryCounts['problem_remains'],
                'cannotCheck' => $recoveryCounts['cannot_check'],
                'staleResponses' => $recoveryCounts['stale'],
                'noResponse' => max(0, $reportCount - $recoveryResponseCount),
            ];
            $myRecoveryQuery = $incident->responses()
                ->where('user_id', $userId)
                ->where('stage', 'recovery')
                ->where('round', $round);
            $result['myRecoveryResponse'] = $isReporter ? (clone $myRecoveryQuery)->value('answer') : null;
            $result['myRecoveryResponseIsFresh'] = $isReporter
                && (clone $myRecoveryQuery)->where('updated_at', '>=', $this->freshnessCutoff())->exists();
            $result['canConfirmRecovery'] = $isReporter;
        }

        if ($includeReports) {
            $result['reports'] = $incident->reports()
                ->latest()
                ->get(['id', 'details', 'created_at'])
                ->map(fn ($report): array => [
                    'details' => $report->details,
                    'createdAt' => $report->created_at->toIso8601String(),
                ])
                ->all();
        }

        return $result;
    }

    /** @return array<string, int> */
    private function responseCounts(Incident $incident, string $stage, int $round): array
    {
        $query = $incident->responses()
            ->where('stage', $stage)
            ->where('round', $round);
        $counts = (clone $query)
            ->where('updated_at', '>=', $this->freshnessCutoff())
            ->selectRaw('answer, COUNT(*) as aggregate')
            ->groupBy('answer')
            ->pluck('aggregate', 'answer');
        $stale = (clone $query)->where('updated_at', '<', $this->freshnessCutoff())->count();

        return [
            'problem_present' => (int) $counts->get('problem_present', 0),
            'service_working' => (int) $counts->get('service_working', 0),
            'cannot_check' => (int) $counts->get('cannot_check', 0),
            'restored' => (int) $counts->get('restored', 0),
            'problem_remains' => (int) $counts->get('problem_remains', 0),
            'stale' => $stale,
        ];
    }

    private function freshnessHours(): int
    {
        return max(1, (int) config('incidents.response_freshness_hours', 24));
    }

    private function freshnessCutoff(): Carbon
    {
        return now()->subHours($this->freshnessHours());
    }
}
