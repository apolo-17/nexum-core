<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores the structured data extracted by Claude vision from a KYC document.
 *
 * Created automatically when AnalyzeDocumentJob processes an approved document.
 * The notary team can review extracted fields in the Filament dashboard and
 * correct any value before the acta constitutiva is generated.
 *
 * One record per document. The `analyzed` flag is false while extraction is
 * pending or failed, and true once Claude successfully extracted at least some fields.
 */
class DocumentAnalysis extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'analyzed',
        'document_number',
        'gender',
        'nationality',
        'birthdate',
        'birthplace',
        'expiry_date',
        'address',
        'country_of_residence',
        'matrimonial_regime',
        'raw_response',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'analyzed' => 'boolean',
            'birthdate' => 'date',
            'expiry_date' => 'date',
            'raw_response' => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the document this analysis belongs to.
     *
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
