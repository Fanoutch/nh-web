<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissingPanne extends Model
{
    use HasFactory;

    protected $fillable = [
        'flight_id', 'failure_code', 'description',
        'reported_by', 'reported_at',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
    ];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
