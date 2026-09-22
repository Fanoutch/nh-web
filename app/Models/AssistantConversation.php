<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une conversation avec l'assistant IA : personnelle (visible par son seul propriétaire).
 */
class AssistantConversation extends Model
{
    protected $fillable = ['user_id', 'secteur_id', 'titre'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function secteur(): BelongsTo
    {
        return $this->belongsTo(Secteur::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id')->orderBy('id');
    }
}
