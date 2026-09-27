<?php

namespace App\Models;

use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['house_id', 'issue_type', 'location', 'status'])]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(IncidentReport::class);
    }
}
