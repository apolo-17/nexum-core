<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a "soldado" — a person whose FIEL (e.firma) credentials are registered
 * with the Secretaría de Economía and used by the Nexum bot to submit denomination
 * reservations to the MUA (Módulo de Uso de Apartado) portal.
 *
 * Each account tracks how many active denominations it is currently processing
 * so that the bot can distribute load across available accounts.
 */
class MuaAccount extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'rfc',
        'is_active',
        'active_submissions',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active'          => 'boolean',
            'active_submissions' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the credentials (certificate, private key, password) for this account.
     *
     * @return HasMany<MuaCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(MuaCredential::class);
    }

    /**
     * Get the legal names currently assigned to this account for MUA processing.
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
     * Determine whether this account is ready to accept new denominations.
     *
     * An account is available when it is active, not soft-deleted,
     * and has all three required credential types stored.
     *
     * @return bool
     */
    public function isReady(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $types = $this->credentials()->pluck('type')->toArray();

        return in_array('certificate', $types, true)
            && in_array('private_key', $types, true)
            && in_array('password', $types, true);
    }

    /**
     * Get a decrypted credential value by type.
     *
     * @param  string  $type  certificate | private_key | password
     *
     * @return string|null
     */
    public function getCredential(string $type): ?string
    {
        $credential = $this->credentials()->where('type', $type)->first();

        return $credential?->decryptedValue();
    }
}
