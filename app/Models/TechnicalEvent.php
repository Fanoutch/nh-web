<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'flight_id', 'technical_event_id', 'raise_datetime',
        'status', 'iso_week', 'nombre_occurrences', 'details',
        'validation_status', 'validated_by', 'validated_at',
    ];

    protected $casts = [
        'raise_datetime' => 'datetime',
        'validated_at' => 'datetime',
        'details' => 'array',
        'nombre_occurrences' => 'int',
    ];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
