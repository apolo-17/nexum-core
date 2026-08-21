<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Services\Document\DocumentAnalysisService;
use Illuminate\Support\Facades\Log;

/**
 * Cross-checks each shareholder against their passport with Claude vision.
 *
 * China sends a romanized name (pinyin) that often differs from the passport — usually just
 * the word order ("HAIYANG JIANG" vs the passport's "JIANG, HAIYANG"), sometimes a different
 * spelling. This service extracts the official name and number from the passport image and:
 *   - when it is the SAME person written differently, offers the passport's spelling so the
 *     acta uses the legal name/order (returned in `officialNames`, safe to adopt);
 *   - when the name looks like a DIFFERENT person, or the passport number does not match,
 *     raises a non-blocking warning for the team to review.
 *
 * Best-effort: a missing passport is a blocking issue handled by ActaCompletenessValidator;
 * here an extraction failure is logged and skipped, never stopping a render.
 */
class KycReconciliationService
{
    public function __construct(
        private readonly DocumentAnalysisService $analysis,
    ) {}

    public function reconcile(Registration $registration): KycReconciliationResult
    {
        $registration->loadMissing(['shareholders', 'documents']);

        $warnings = [];
        $officialNames = [];

        foreach ($registration->shareholders->values() as $position => $shareholder) {
            $index = $position + 1;
            $passport = $this->passportFor($registration, $index);

            if ($passport === null) {
                continue;
            }

            try {
                $analysis = $this->analysis->analyse($passport);
            } catch (\Throwable $exception) {
                Log::warning('KycReconciliationService: passport analysis failed.', [
                    'document_id' => $passport->id,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($analysis === null) {
                continue;
            }

            $officialName = $analysis->full_name;
            $officialNumber = $analysis->document_number;

            if (filled($officialName)) {
                if ($this->sameName((string) $shareholder->name, $officialName)) {
                    if ($shareholder->name !== $officialName) {
                        $officialNames[$shareholder->id] = $officialName;
                    }
                } else {
                    $warnings[] = "Revisa el nombre del socio {$index}: en el sistema «{$shareholder->name}», "
                        ."en el pasaporte «{$officialName}» (no parecen la misma persona).";
                }
            }

            if (filled($officialNumber) && filled($shareholder->passport_number)
                && strtoupper(trim($officialNumber)) !== strtoupper(trim((string) $shareholder->passport_number))) {
                $warnings[] = "El pasaporte del socio {$index}: número «{$officialNumber}» no coincide con el declarado «{$shareholder->passport_number}».";
            }
        }

        return new KycReconciliationResult($warnings, $officialNames);
    }

    private function passportFor(Registration $registration, int $index): ?Document
    {
        return $registration->documents->first(
            fn (Document $document): bool => in_array(
                $document->type,
                [DocumentTypeEnum::PASSPORT, DocumentTypeEnum::KYC_SPOUSE_PASSPORT],
                true,
            )
                && (int) $document->shareholder_index === $index
                && filled($document->storage_path),
        );
    }

    /**
     * Same person? Compare the alphabetic tokens as an unordered set, so a mere word-order
     * difference ("HAIYANG JIANG" vs "JIANG HAIYANG") counts as a match while a genuinely
     * different name does not.
     */
    private function sameName(string $a, string $b): bool
    {
        $tokens = static fn (string $value): array => collect(preg_split('/[^\p{L}]+/u', mb_strtoupper($value)))
            ->filter()
            ->sort()
            ->values()
            ->all();

        return $tokens($a) === $tokens($b);
    }
}
