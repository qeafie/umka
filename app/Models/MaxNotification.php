<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['incident_id', 'user_id', 'membership_id', 'token', 'kind', 'round', 'expected_status', 'event_key', 'entrance', 'floor', 'status', 'attempts', 'last_error', 'available_at', 'expires_at', 'sent_at'])]
#[Hidden(['token'])]
class MaxNotification extends Model
{
    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'expires_at' => 'datetime', 'sent_at' => 'datetime', 'round' => 'integer', 'attempts' => 'integer'];
    }
}
