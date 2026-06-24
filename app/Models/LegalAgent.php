<?php

namespace App\Models;

use App\Enums\LegalAgentTypeEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable legal representative or commissary in the notary firm's catalog.
 *
 * Foreign-owned companies require an acta constitutiva with a legal representative
 * and a commissary. Rather than re-typing them per acta, the notary team keeps a
 * catalog of these profiles and assigns them to actas (registrations) via the
 * legal_agent_registration pivot, with the share percentage for each acta.
 */
class LegalAgent extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'nationality',
        'rfc',
        'curp',
        'email',
        'phone',
        'birthdate',
        'birthplace',
        'address',
        'notes',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LegalAgentTypeEnum::class,
            'birthdate' => 'date',
            'is_active' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the actas (registrations) this agent is assigned to.
     *
     * The pivot carries the share percentage held in each acta.
     *
     * @return BelongsToMany<Registration, $this>
     */
    public function registrations(): BelongsToMany
    {
        return $this->belongsToMany(Registration::class, 'legal_agent_registration')
            ->withPivot('participation_percentage')
            ->withTimestamps();
    }

    // -------------------------------------------------------------------------
    // Computed helpers
    // -------------------------------------------------------------------------

    /**
     * Return true when this catalog entry is a legal representative.
     */
    public function isLegalRepresentative(): bool
    {
        return $this->type === LegalAgentTypeEnum::LEGAL_REPRESENTATIVE;
    }

    /**
     * Return true when this catalog entry is a commissary.
     */
    public function isCommissary(): bool
    {
        return $this->type === LegalAgentTypeEnum::COMMISSARY;
    }
}
