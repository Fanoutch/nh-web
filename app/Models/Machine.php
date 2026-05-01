<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends Model
{
    use HasFactory;

    protected $fillable = ['hc_id'];

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    public function weeklyAggregates(): HasMany
    {
        return $this->hasMany(WeeklyAggregate::class);
    }

    public function recurrentFailures(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RecurrentFailure::class);
    }
}
