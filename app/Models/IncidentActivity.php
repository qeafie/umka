<?php

namespace App\Models;

use Database\Factories\IncidentActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['incident_id', 'user_id', 'event_type', 'payload'])]
class IncidentActivity extends Model
{
    /** @use HasFactory<IncidentActivityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
