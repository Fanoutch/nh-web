<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Un secteur (ex. BMN) : ses membres (chef / utilisateur) et ses outils (Disponibilités, Assistant IA).
 * Droits : App\Policies\SecteurPolicy.
 */
class Secteur extends Model
{
    public const ROLE_CHEF = 'chef';
    public const ROLE_UTILISATEUR = 'utilisateur';

    protected $fillable = ['slug', 'nom'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }
}
