<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['house_id', 'created_by', 'token_hash', 'apartment', 'entrance', 'floor', 'expires_at', 'revoked_at', 'accepted_at', 'accepted_by'])]
#[Hidden(['token_hash'])]
class HouseInvitation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'accepted_at' => 'datetime', 'entrance' => 'integer', 'floor' => 'integer'];
    }
}
