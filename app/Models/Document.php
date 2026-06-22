<?php

namespace App\Models;

use App\Enums\DocumentTypeEnum;
use App\Enums\RegistrationStageEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a file attached to a registration expedient.
 *
 * Documents arrive from the Singapur webhook as base64 content and are stored
 * in R2 (or local disk in development). The storage_path column holds the
 * path where the file was persisted. Soft-deleted records are retained for audit.
 */
class Document extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'registration_id',
        'type',
        'name',
        'storage_path',
        'google_drive_file_id',
        'google_drive_url',
        'stage',
        'shareholder_index',
        'template_data',
        'uploaded_by',
        'verified_at',
        'verified_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentTypeEnum::class,
            'stage' => RegistrationStageEnum::class,
            'template_data' => 'array',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the registration this document belongs to.
     *
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * Get the user who uploaded this document.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the user who verified this document.
     *
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Get the user who rejected this document.
     *
     * @return BelongsTo<User, $this>
     */
    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    // -------------------------------------------------------------------------
    // File type helpers
    // -------------------------------------------------------------------------

    /**
     * Derive the MIME type from the stored file extension without hitting storage.
     *
     * Used by the preview blade to choose the appropriate renderer (PDF.js vs
     * native <img> tag). Falls back to 'application/octet-stream' for unknown
     * extensions.
     *
     * @return string MIME type string, e.g. 'application/pdf' or 'image/jpeg'.
     */
    public function mimeType(): string
    {
        if (blank($this->storage_path)) {
            return 'application/octet-stream';
        }

        $ext = strtolower(pathinfo($this->storage_path, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * Return true if the stored file is an image (jpeg, png, gif, webp).
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mimeType(), 'image/');
    }

    /**
     * Return true if the stored file is a PDF document.
     */
    public function isPdf(): bool
    {
        return $this->mimeType() === 'application/pdf';
    }

    // -------------------------------------------------------------------------
    // Evaluation helpers
    // -------------------------------------------------------------------------

    /**
     * Return the evaluation state as a string for display purposes.
     *
     * States:
     *   approved → verified_at is set
     *   rejected → rejected_at is set
     *   pending  → neither is set
     *
     * @return string 'approved' | 'rejected' | 'pending'
     */
    public function evaluationStatus(): string
    {
        if ($this->verified_at !== null) {
            return 'approved';
        }

        if ($this->rejected_at !== null) {
            return 'rejected';
        }

        return 'pending';
    }

    /**
     * Return true when the document already has a final evaluation (approved or rejected).
     *
     * Once a document is evaluated its status is final and cannot be changed.
     * Only pending documents may be approved or rejected, individually or in bulk.
     */
    public function isEvaluated(): bool
    {
        return $this->evaluationStatus() !== 'pending';
    }
}
