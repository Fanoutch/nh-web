<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flight extends Model
{
    use HasFactory;

    protected $fillable = [
        'machine_id', 'dsn', 'num',
        'start_datetime', 'end_datetime',
        'flight_type', 'flight_hours', 'consumed_fuel', 'remarks',
        'is_non_vol', 'flagged_as_error', 'flagged_at', 'flagged_by',
        'xml_path', 'xml_blob', 'processed_at',
    ];

    /**
     * xml_blob is binary, never serialize it into a JSON/Livewire response.
     * Access it explicitly via $flight->xml_blob when needed (e.g. download route).
     */
    protected $hidden = [
        'xml_blob',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'flagged_at' => 'datetime',
        'processed_at' => 'datetime',
        'is_non_vol' => 'bool',
        'flagged_as_error' => 'bool',
        'flight_hours' => 'decimal:4',
        'consumed_fuel' => 'decimal:2',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function technicalEvents(): HasMany
    {
        return $this->hasMany(TechnicalEvent::class);
    }

    public function missingPannes(): HasMany
    {
        return $this->hasMany(MissingPanne::class);
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }
}
