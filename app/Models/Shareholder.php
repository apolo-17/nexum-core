<?php

namespace App\Models;

use App\Enums\DocumentTypeEnum;
use App\Enums\ShareholderRoleEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a shareholder or legal representative of the company being incorporated.
 *
 * Each shareholder belongs to a registration and carries identity data
 * received from the Singapur relay (passport, nationality, participation percentage).
 */
class Shareholder extends Model
{
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'registration_id',
        'name',
        'nationality',
        'passport_number',
        'participation_percentage',
        'role',
        'email',
        'phone',
        'is_married',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ShareholderRoleEnum::class,
            'participation_percentage' => 'decimal:2',
            'is_married' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // KYC document expectations
    // -------------------------------------------------------------------------

    /**
     * Return the list of KYC document types expected from China for this shareholder.
     *
     * Every shareholder requires a tax certificate and a proof of address.
     * Married shareholders additionally require a marriage certificate and the
     * spouse's passport. This drives the missing-document detection logic on
     * the registration, so the notary team can spot incomplete packages early.
     *
     * @return list<DocumentTypeEnum>
     */
    public function expectedKycDocumentTypes(): array
    {
        $types = [
            DocumentTypeEnum::KYC_TAX_CERTIFICATE,
            DocumentTypeEnum::KYC_PROOF_OF_ADDRESS,
        ];

        if ($this->is_married) {
            $types[] = DocumentTypeEnum::KYC_MARRIAGE_CERTIFICATE;
            $types[] = DocumentTypeEnum::KYC_SPOUSE_PASSPORT;
        }

        return $types;
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the registration this shareholder belongs to.
     *
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
