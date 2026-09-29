<?php

namespace App\Models;

use Database\Factories\IncidentResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['incident_id', 'user_id', 'stage', 'round', 'answer', 'entrance', 'floor'])]
class IncidentResponse extends Model
{
    /** @use HasFactory<IncidentResponseFactory> */
    use HasFactory;

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
