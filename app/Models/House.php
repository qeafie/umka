<?php

namespace App\Models;

use Database\Factories\HouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'address'])]
class House extends Model
{
    /** @use HasFactory<HouseFactory> */
    use HasFactory;

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'house_memberships')
            ->withPivot(['role', 'apartment'])
            ->withTimestamps();
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function membershipActivities(): HasMany
    {
        return $this->hasMany(HouseMembershipActivity::class);
    }
}
