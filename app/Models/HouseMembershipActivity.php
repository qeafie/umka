<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['house_id', 'actor_id', 'member_id', 'event_type', 'payload'])]
class HouseMembershipActivity extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }
}
