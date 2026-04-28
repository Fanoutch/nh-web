<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyAggregate extends Model
{
    use HasFactory;

    protected $fillable = [
        'machine_id', 'year', 'iso_week',
        'total_pannes', 'total_flight_hours',
    ];

    protected $casts = [
        'year' => 'int',
        'total_pannes' => 'int',
        'total_flight_hours' => 'decimal:4',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
