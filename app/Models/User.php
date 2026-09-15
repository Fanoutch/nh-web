<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'is_super_admin',
        'is_personnel_navigant',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_super_admin' => 'boolean',
            'is_personnel_navigant' => 'boolean',
        ];
    }

    /**
     * Un super admin compte aussi comme admin (acces aux pages admin).
     */
    public function isAdmin(): bool
    {
        return $this->is_admin || $this->is_super_admin;
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function isPersonnelNavigant(): bool
    {
        return (bool) $this->is_personnel_navigant;
    }

    public function secteurs(): BelongsToMany
    {
        return $this->belongsToMany(Secteur::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Rôle dans le secteur ('chef' | 'utilisateur'), null si non membre.
     * Un admin non membre renvoie null : ses droits viennent de isAdmin() (SecteurPolicy).
     */
    public function roleDans(Secteur $secteur): ?string
    {
        return $this->secteurs()->where('secteurs.id', $secteur->id)->first()?->pivot->role;
    }

    /** Secteurs visibles : tous pour un admin, sinon ceux dont l'utilisateur est membre. */
    public function secteursAccessibles(): Collection
    {
        return $this->isAdmin()
            ? Secteur::orderBy('nom')->get()
            : $this->secteurs()->orderBy('nom')->get();
    }
}
