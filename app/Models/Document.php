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
 * Represents a file attached to a registration expedient and stored in Google Drive.
 *
 * Documents are uploaded by the notary team during specific stages.
 * The google_drive_file_id is the authoritative reference; the URL is cached for quick access.
 * Soft-deleted documents are retained for audit purposes.
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
        'google_drive_file_id',
        'google_drive_url',
        'stage',
        'uploaded_by',
        'verified_at',
        'verified_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type'        => DocumentTypeEnum::class,
            'stage'       => RegistrationStageEnum::class,
            'verified_at' => 'datetime',
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
}
