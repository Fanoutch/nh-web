<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantMessage extends Model
{
    public const UTILISATEUR = 'utilisateur';
    public const ASSISTANT = 'assistant';

    public const COMPLET = 'complet';
    public const ERREUR = 'erreur';
    public const INTERROMPU = 'interrompu';

    protected $fillable = ['conversation_id', 'role', 'contenu', 'sources', 'pieces', 'statut', 'detail', 'signale_at'];

    protected function casts(): array
    {
        return ['sources' => 'array', 'pieces' => 'array', 'signale_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }
}
