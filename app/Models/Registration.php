<?php

namespace App\Models;

use App\Enums\EfirmaAppointmentStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'rfc',
        'efirma_appointment_at',
        'efirma_status',
        'efirma_key_path',
        'efirma_cer_path',
        'efirma_password_hash',
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
            'stage'                  => RegistrationStageEnum::class,
            'status'                 => RegistrationStatusEnum::class,
            'efirma_appointment_at'  => 'datetime',
            'efirma_status'          => EfirmaAppointmentStatusEnum::class,
            'completed_at'           => 'datetime',
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
     * Get all proposed legal denominations for this registration.
     *
     * @return HasMany<LegalName, $this>
     */
    public function legalNames(): HasMany
    {
        return $this->hasMany(LegalName::class)->orderBy('priority');
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
}
