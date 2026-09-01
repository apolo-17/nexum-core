<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Represents a notary team member with role-based access to the Nexum dashboard.
 *
 * Roles managed via Spatie (super_admin, notario, asistente_notario).
 * Implements JWTSubject to issue tokens consumed by the Chinese client frontend.
 * Implements FilamentUser to control dashboard access per role.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Determine whether this user can access the Filament admin panel.
     *
     * Only notary team members with an assigned role are granted entry.
     * Unauthenticated users and those without a recognized role receive a 403.
     *
     * @param  Panel  $panel  The Filament panel being accessed.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // A linked soldado profile always grants access, even if the role assignment
        // had a guard/seed hiccup — otherwise an invited soldado could be locked out
        // right after setting their password.
        return $this->hasAnyRole(['super_admin', 'notario', 'asistente_notario', 'soldado', 'partner'])
            || $this->soldado()->exists();
    }

    /**
     * A "partner" is an external ally (e.g. helping with the bank process) with
     * read-only access: they may view expedientes and download documents, but never
     * the e.firma credentials, and cannot create/edit/delete anything.
     */
    public function isPartner(): bool
    {
        return $this->hasRole('partner');
    }

    /**
     * Get the soldado profile linked to this user account, if any.
     *
     * Only users invited as soldados have a linked profile; notary-team users do not.
     *
     * @return HasOne<Soldado, $this>
     */
    public function soldado(): HasOne
    {
        return $this->hasOne(Soldado::class);
    }

    /**
     * Get the identifier that will be stored in the JWT subject claim.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return a key-value array of arbitrary claims to be added to the JWT payload.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->roles->pluck('name')->first(),
        ];
    }

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
            'system_notice_ack_at' => 'datetime',
        ];
    }

    /**
     * Normaliza el correo a minúscula (y sin espacios) SIEMPRE que se guarda, sin importar
     * el origen (registro, invitación, alta manual, updates). Así el login por correo nunca
     * falla por diferencias de mayúsculas/minúsculas (Postgres distingue mayúsculas).
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : mb_strtolower(trim($value)),
        );
    }
}
