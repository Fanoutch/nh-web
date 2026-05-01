<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function recurrentFailures(): HasMany
    {
        return $this->hasMany(RecurrentFailure::class);
    }

    public function latestFlight(): HasOne
    {
        return $this->hasOne(Flight::class)->latestOfMany('start_datetime');
    }
}
