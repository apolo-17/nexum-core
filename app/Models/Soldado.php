<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a "soldado" — a contracted Mexican person used by Nexum.
 *
 * One real person, modeled once. Capabilities (flags) decide what they may be used
 * for: MUA operator (lends their FIEL to request denominations) and/or legal
 * representative / commissary in the incorporation act. The FIEL is stored once
 * (soldado_credentials) and reused by whichever capability needs it.
 *
 * Dashboard login is optional: `user` is linked only when the super_admin grants
 * access. Soft-deleting a soldado is the "dar de baja" operation.
 */
class Soldado extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'phone_country_code',
        'rfc',
        'curp',
        'fiel_valid_until',
        'birthdate',
        'birthplace',
        'address',
        'ine_front_path',
        'ine_back_path',
        'available_for_mua',
        'mua_blocked_reason',
        'mua_blocked_at',
        'available_as_legal_representative',
        'available_as_commissary',
        'is_active',
        'active_submissions',
        'user_id',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'fiel_valid_until' => 'date',
            'available_for_mua' => 'boolean',
            'mua_blocked_at' => 'datetime',
            'available_as_legal_representative' => 'boolean',
            'available_as_commissary' => 'boolean',
            'is_active' => 'boolean',
            'active_submissions' => 'integer',
        ];
    }

    /**
     * Whether the soldado's own e.firma (FIEL) is still valid today.
     *
     * Advisory only: the SAT admits a soldado to an e.firma appointment only if their personal
     * FIEL is vigente. Null date = unknown (treated as not-vigente for the indicator).
     */
    public function fielVigente(): bool
    {
        return $this->fiel_valid_until !== null && ! $this->fiel_valid_until->isPast();
    }

    /**
     * Whether we hold a complete legal-rep profile: RFC + CURP + a vigente FIEL.
     *
     * Informative (never blocks); lets the team see at a glance who can attend an e.firma cita.
     */
    public function legalRepProfileComplete(): bool
    {
        return filled($this->rfc) && filled($this->curp) && $this->fielVigente();
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the FIEL credentials (certificate, private key, password) for this soldado.
     *
     * @return HasMany<SoldadoCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(SoldadoCredential::class);
    }

    /**
     * Get the linked dashboard user account, if access was granted.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the SAT appointments this soldado is assigned to attend.
     *
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Get the registrations (actas) this soldado acts in as rep / commissary.
     *
     * @return BelongsToMany<Registration, $this>
     */
    public function registrations(): BelongsToMany
    {
        return $this->belongsToMany(Registration::class, 'registration_soldado')
            ->withPivot('role', 'participation_percentage')
            ->withTimestamps();
    }

    /**
     * Get the denominations submitted with this soldado's FIEL.
     *
     * @return HasMany<LegalName, $this>
     */
    public function legalNames(): HasMany
    {
        return $this->hasMany(LegalName::class);
    }

    // -------------------------------------------------------------------------
    // Business logic helpers
    // -------------------------------------------------------------------------

    /**
     * Determine whether this soldado is ready to operate the MUA portal.
     *
     * Ready when active, not soft-deleted, flagged for MUA and holding all three
     * required credential types.
     */
    public function isReadyForMua(): bool
    {
        if (! $this->is_active || ! $this->available_for_mua) {
            return false;
        }

        $types = $this->credentials()->pluck('type')->all();

        return in_array('certificate', $types, true)
            && in_array('private_key', $types, true)
            && in_array('password', $types, true);
    }

    /**
     * Get a decrypted credential value by type.
     *
     * @param  string  $type  certificate | private_key | password
     */
    public function getCredential(string $type): ?string
    {
        return $this->credentials()->where('type', $type)->first()?->decryptedValue();
    }

    /**
     * Determine whether this soldado has been granted dashboard access.
     */
    public function hasPanelAccess(): bool
    {
        return $this->user_id !== null;
    }
}
