<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurrentFailure extends Model
{
    protected $fillable = [
        'machine_id',
        'technical_event_id',
        'status',
        'te_description',
        'description',
        'system_description',
        'type_description',
        'failure_code',
        'active_depuis_vol',
        'active_depuis_date',
        'first_apparition',
        'score',
        'details',
    ];

    protected $casts = [
        'active_depuis_date' => 'date',
        'first_apparition'   => 'datetime',
        'score'              => 'integer',
        'details'            => 'array',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
