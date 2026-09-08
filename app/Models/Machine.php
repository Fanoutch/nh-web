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

    /**
     * Dernier verdict PN (confirmé/rejeté) par code de panne, pour cette machine.
     * Retourne une collection de TechnicalEvent indexée par technical_event_id.
     */
    public function latestPnVerdicts(): \Illuminate\Support\Collection
    {
        return TechnicalEvent::whereIn('pn_validation_status', ['confirmed', 'rejected'])
            ->whereHas('flight', fn ($q) => $q->where('machine_id', $this->id))
            ->with('pnValidator')
            ->orderByDesc('pn_validated_at')
            ->get()
            ->unique('technical_event_id')
            ->keyBy('technical_event_id');
    }

    public function scopeActive($query, int $threshold = 3, int $days = 30)
    {
        return $query->whereHas('flights', function ($q) use ($days) {
            $q->where('is_non_vol', false)
              ->where('flagged_as_error', false)
              ->where('start_datetime', '>=', now()->subDays($days));
        }, '>=', $threshold);
    }
}
