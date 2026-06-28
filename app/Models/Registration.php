<?php

namespace App\Models;

use App\Enums\DocumentTypeEnum;
use App\Enums\EfirmaAppointmentStatusEnum;
use App\Enums\LegalAgentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a single company incorporation expedient for a Chinese client.
 *
 * Central model of the Nexum domain. All other entities (shareholders, documents,
 * tasks, notes, stage transitions) belong to a registration.
 * Data arrives from the Singapur relay and is tracked through 8 sequential stages.
 */
class Registration extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'singapur_client_code',
        'singapur_package_id',
        'singapur_folder_name',
        'stage',
        'status',
        'assigned_notario_id',
        'assigned_asistente_id',
        'company_type',
        'company_object',
        'capital_social',
        'rfc',
        'efirma_appointment_at',
        'efirma_status',
        'efirma_key_path',
        'efirma_cer_path',
        'efirma_password_hash',
        'company_fiel_cer_path',
        'company_fiel_key_path',
        'company_fiel_password',
        'company_rfc_path',
        'notes_count',
        'tasks_pending_count',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => RegistrationStageEnum::class,
            'status' => RegistrationStatusEnum::class,
            'capital_social' => 'decimal:2',
            'efirma_appointment_at' => 'datetime',
            'efirma_status' => EfirmaAppointmentStatusEnum::class,
            // Reversibly encrypted so the company e.firma password can be retrieved for download.
            'company_fiel_password' => 'encrypted',
            'completed_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the notary assigned to this registration.
     *
     * @return BelongsTo<User, $this>
     */
    public function notario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_notario_id');
    }

    /**
     * Get the assistant assigned to this registration.
     *
     * @return BelongsTo<User, $this>
     */
    public function asistente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_asistente_id');
    }

    /**
     * Get all shareholders belonging to the company being incorporated.
     *
     * @return HasMany<Shareholder, $this>
     */
    public function shareholders(): HasMany
    {
        return $this->hasMany(Shareholder::class);
    }

    /**
     * Get all soldados acting in this acta (legal representatives and commissaries).
     *
     * The pivot carries the role each soldado plays in this acta and the share
     * percentage they hold. A soldado flagged for both capabilities can therefore
     * act in either role depending on the acta.
     *
     * @return BelongsToMany<Soldado, $this>
     */
    public function soldados(): BelongsToMany
    {
        return $this->belongsToMany(Soldado::class, 'registration_soldado')
            ->withPivot('role', 'participation_percentage')
            ->withTimestamps();
    }

    /**
     * Get the soldados acting as legal representatives in this acta.
     *
     * @return BelongsToMany<Soldado, $this>
     */
    public function legalRepresentatives(): BelongsToMany
    {
        return $this->soldados()
            ->wherePivot('role', LegalAgentTypeEnum::LEGAL_REPRESENTATIVE->value);
    }

    /**
     * Get the soldados acting as commissaries in this acta.
     *
     * @return BelongsToMany<Soldado, $this>
     */
    public function commissaries(): BelongsToMany
    {
        return $this->soldados()
            ->wherePivot('role', LegalAgentTypeEnum::COMMISSARY->value);
    }

    /**
     * Get all proposed legal denominations for this registration.
     *
     * @return HasMany<LegalName, $this>
     */
    public function legalNames(): HasMany
    {
        return $this->hasMany(LegalName::class)->orderBy('priority');
    }

    /**
     * Get the primary (priority-1) legal name proposed for this registration.
     *
     * Exposed as a HasOne to allow efficient eager-loading in table views,
     * preventing N+1 queries when listing many registrations simultaneously.
     * Use this relation when you only need the company display name.
     *
     * @return HasOne<LegalName, $this>
     */
    public function primaryLegalName(): HasOne
    {
        return $this->hasOne(LegalName::class)->where('priority', 1)->orderBy('priority');
    }

    /**
     * Get all documents attached to this registration expedient.
     *
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Get the full history of stage transitions for this registration.
     *
     * @return HasMany<StageTransition, $this>
     */
    public function stageTransitions(): HasMany
    {
        return $this->hasMany(StageTransition::class)->orderBy('created_at');
    }

    /**
     * Get all tasks associated with this registration.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Get all internal notes written by the notary team.
     *
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class)->orderByDesc('is_pinned')->orderByDesc('created_at');
    }

    /**
     * Get the SAT appointments (RFC and FIEL) for this company.
     *
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class)->orderBy('type')->orderByDesc('created_at');
    }

    // -------------------------------------------------------------------------
    // KYC completeness
    // -------------------------------------------------------------------------

    /**
     * Calculate which KYC document types are expected but not yet received.
     *
     * Uses each shareholder's is_married flag to compute the expected count per
     * KYC document type, then subtracts what is actually present in documents.
     * Returns a map of DocumentTypeEnum value → number of missing copies.
     *
     * Example — 2 married shareholders, but one marriage cert missing:
     *   ['kyc_marriage_certificate' => 1]
     *
     * An empty array means the KYC package is complete.
     *
     * @return array<string, int> Map of DocumentTypeEnum::value → missing count.
     */
    public function missingKycDocuments(): array
    {
        $shareholders = $this->relationLoaded('shareholders')
            ? $this->shareholders
            : $this->shareholders()->get();

        $documents = $this->relationLoaded('documents')
            ? $this->documents
            : $this->documents()->get();

        // Build expected count per KYC type summing across all shareholders.
        $expected = [];

        foreach ($shareholders as $shareholder) {
            foreach ($shareholder->expectedKycDocumentTypes() as $type) {
                $expected[$type->value] = ($expected[$type->value] ?? 0) + 1;
            }
        }

        if (empty($expected)) {
            return [];
        }

        // Build actual count per KYC type from received documents.
        $actual = [];

        foreach ($documents as $document) {
            if ($document->type->isKyc()) {
                $actual[$document->type->value] = ($actual[$document->type->value] ?? 0) + 1;
            }
        }

        // Return types where expected count exceeds actual count.
        $missing = [];

        foreach ($expected as $typeValue => $expectedCount) {
            $deficit = $expectedCount - ($actual[$typeValue] ?? 0);

            if ($deficit > 0) {
                $missing[$typeValue] = $deficit;
            }
        }

        return $missing;
    }

    /**
     * Return true if any KYC document expected from China has not yet arrived.
     *
     * Convenience wrapper around missingKycDocuments() for conditional rendering.
     */
    public function hasMissingKycDocuments(): bool
    {
        return ! empty($this->missingKycDocuments());
    }
}
