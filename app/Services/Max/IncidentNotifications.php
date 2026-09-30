<?php

namespace App\Services\Max;

use App\Models\Incident;
use App\Models\MaxNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class IncidentNotifications
{
    public function scope(Incident $incident, int $entrance, int $from, int $to): int
    {
        $queued = 0;
        foreach ($incident->house->members()->wherePivot('role', 'resident')->wherePivot('notifications_enabled', true)
            ->wherePivot('entrance', $entrance)->wherePivotBetween('floor', [$from, $to])->whereNotNull('max_user_id')->get() as $resident) {
            if (! $this->hasFreshAnswer($incident, $resident, 'scope')) {
                $queued += $this->enqueue($incident, $resident, 'scope');
            }
        }

        return $queued;
    }

    public function workflow(Incident $incident): void
    {
        $kind = $incident->status === 'work_completed' ? 'recovery' : 'status';
        foreach ($incident->house->members()->wherePivot('role', 'resident')->wherePivot('notifications_enabled', true)
            ->whereNotNull('max_user_id')->whereIn('users.id', $incident->reports()->select('user_id'))->get() as $resident) {
            if ($kind === 'status' || ! $this->hasFreshAnswer($incident, $resident, 'recovery')) {
                $this->enqueue($incident, $resident, $kind);
            }
        }
    }

    public function eligible(MaxNotification $notification, Incident $incident): ?User
    {
        if ($notification->expires_at->lte(now()) || (int) $incident->recovery_round !== $notification->round) {
            return null;
        }
        if ($notification->kind === 'scope' && ! in_array($incident->status, ['reported', 'in_progress'], true)) {
            return null;
        }
        if ($notification->kind !== 'scope' && $incident->status !== $notification->expected_status) {
            return null;
        }
        $resident = $incident->house->members()->whereKey($notification->user_id)->wherePivot('id', $notification->membership_id)
            ->wherePivot('role', 'resident')->wherePivot('notifications_enabled', true)->whereNotNull('max_user_id')->lockForUpdate()->first();
        if ($resident === null || $resident->pivot->entrance != $notification->entrance || $resident->pivot->floor != $notification->floor) {
            return null;
        }
        if ($notification->kind !== 'scope' && ! $incident->reports()->where('user_id', $resident->id)->exists()) {
            return null;
        }

        return $resident;
    }

    public function hasFreshAnswer(Incident $incident, User $resident, string $kind): bool
    {
        return $incident->responses()->where('user_id', $resident->id)->where('stage', $kind)
            ->where('round', $kind === 'scope' ? 0 : $incident->recovery_round)
            ->where('entrance', $resident->pivot->entrance)->where('floor', $resident->pivot->floor)
            ->where('updated_at', '>=', now()->subHours(max(1, (int) config('incidents.response_freshness_hours', 24))))->exists();
    }

    private function enqueue(Incident $incident, User $resident, string $kind): int
    {
        $eventKey = $kind === 'status' ? hash('sha256', json_encode([$incident->status, $incident->assigned_to, $incident->next_action, $incident->next_update_at])) : null;
        $membershipId = DB::table('house_memberships')->where('house_id', $incident->house_id)->where('user_id', $resident->id)->value('id');
        $existing = MaxNotification::query()->where('incident_id', $incident->id)->where('user_id', $resident->id)
            ->where('membership_id', $membershipId)->where('kind', $kind)->where('round', $incident->recovery_round)
            ->whereNotIn('status', ['cancelled', 'failed'])->where('event_key', $eventKey)->where('entrance', $resident->pivot->entrance)->where('floor', $resident->pivot->floor)
            ->where('expires_at', '>', now())->exists();
        if ($existing) {
            return 0;
        }
        if ($kind === 'status') {
            MaxNotification::query()->where('incident_id', $incident->id)->where('user_id', $resident->id)
                ->where('kind', 'status')->where('status', 'pending')->update(['status' => 'cancelled']);
        }
        MaxNotification::query()->create([
            'incident_id' => $incident->id, 'user_id' => $resident->id, 'membership_id' => $membershipId,
            'token' => bin2hex(random_bytes(16)), 'kind' => $kind, 'round' => $incident->recovery_round,
            'expected_status' => $incident->status, 'event_key' => $eventKey,
            'entrance' => $resident->pivot->entrance, 'floor' => $resident->pivot->floor,
            'available_at' => now(), 'expires_at' => now()->addHours(max(1, (int) config('incidents.response_freshness_hours', 24))),
        ]);

        return 1;
    }
}
