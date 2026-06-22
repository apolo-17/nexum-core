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
        'phone_country_code',
        'is_married',
        'gender',
        'birthdate',
        'birthplace',
        'civil_status',
        'tax_id',
        'address_line',
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
            'birthdate' => 'date',
        ];
    }

    // -------------------------------------------------------------------------
    // Computed helpers
    // -------------------------------------------------------------------------

    /**
     * Return the effective civil status for use in the acta constitutiva.
     *
     * If civil_status was provided by the relay or set manually it is returned
     * as-is. Otherwise it is derived from the is_married boolean as a fallback.
     */
    public function effectiveCivilStatus(): string
    {
        if (filled($this->civil_status)) {
            return $this->civil_status;
        }

        return $this->is_married ? 'casado' : 'soltero';
    }

    /**
     * Return the RFC for use in the acta constitutiva.
     *
     * Chinese and other foreign nationals do not have a Mexican RFC. The SAT
     * assigns the standard generic RFC EXTF900101NI1 to all foreign natural
     * persons. Mexican nationals should have their RFC set manually.
     */
    public function effectiveRfc(): string
    {
        // Chinese nationals always use the generic foreigner RFC.
        if (strtolower($this->nationality) === 'china' || strtolower($this->nationality) === 'chinese') {
            return 'EXTF900101NI1';
        }

        // Other foreigners also use the generic RFC unless a real one was set.
        // Mexican nationals should have their real RFC in tax_id or a dedicated field.
        return 'EXTF900101NI1';
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
