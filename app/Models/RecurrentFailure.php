<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurrentFailure extends Model
{
    protected $guarded = [];

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
