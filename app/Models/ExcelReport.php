<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une génération d'Excel à partir d'un CSV déposé sur la page « Rapport Excel ».
 */
class ExcelReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'filename', 'status', 'output_path', 'output_name', 'rows_written', 'message',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ok'
            && $this->output_path !== null
            && is_file($this->output_path);
    }
}
