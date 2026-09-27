<?php

namespace App\Models;

use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['house_id', 'issue_type', 'location', 'status', 'assigned_to', 'next_action', 'next_update_at', 'work_completed_at', 'recovery_round'])]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'next_update_at' => 'datetime',
            'work_completed_at' => 'datetime',
        ];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(IncidentReport::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(IncidentResponse::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(IncidentActivity::class);
    }
}
