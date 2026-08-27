<?php

declare(strict_types=1);

namespace App\Services\Singapur;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;

/**
 * Computes, per registration, the delivery status of the five documents China needs.
 *
 * A deliverable is:
 *   - "delivered": a document of that type exists AND China confirmed it (relay_delivered_at).
 *   - "pending":   the document exists (has a file) but China has not confirmed it yet.
 *   - "missing":   no document of that type has been uploaded.
 *
 * All five deliverables are Documents (the e.firma is materialised as an EFIRMA document when
 * the soldado uploads it — see MisCitasResource), so the logic is uniform.
 */
class ChinaDeliverablesService
{
    /**
     * The five deliverables China pulls, in display order: document type => short label.
     *
     * @var array<string, string>
     */
    public const DELIVERABLES = [
        DocumentTypeEnum::ACTA_PROTOCOLIZADA->value => 'Acta protocolizada',
        DocumentTypeEnum::RPP_REGISTRATION->value => 'RPP (registro público)',
        DocumentTypeEnum::PROOF_OF_ADDRESS_MX->value => 'Comprobante de domicilio',
        DocumentTypeEnum::CSF->value => 'CSF',
        DocumentTypeEnum::EFIRMA->value => 'e.firma',
    ];

    /** Total number of deliverables China expects per company. */
    public function total(): int
    {
        return count(self::DELIVERABLES);
    }

    /**
     * Per-deliverable status for a registration.
     *
     * @return list<array{type: string, label: string, state: 'delivered'|'pending'|'missing', drive_url: ?string, delivered_at: ?\Illuminate\Support\Carbon}>
     */
    public function statusFor(Registration $registration): array
    {
        $docs = Document::query()
            ->where('registration_id', $registration->id)
            ->whereIn('type', array_keys(self::DELIVERABLES))
            ->whereNotNull('storage_path')
            ->get()
            ->keyBy(fn (Document $d): string => $d->type instanceof DocumentTypeEnum ? $d->type->value : (string) $d->type);

        $out = [];
        foreach (self::DELIVERABLES as $type => $label) {
            $doc = $docs->get($type);
            $state = match (true) {
                $doc === null => 'missing',
                $doc->relay_rejected_at !== null => 'rejected',
                $doc->relay_delivered_at !== null => 'delivered',
                default => 'pending',
            };

            $out[] = [
                'type' => $type,
                'label' => $label,
                'state' => $state,
                'drive_url' => $doc?->relay_drive_url,
                'delivered_at' => $doc?->relay_delivered_at,
                'rejection_reason' => $doc?->relay_rejection_reason,
            ];
        }

        return $out;
    }

    /** How many of the five deliverables China has already confirmed for this registration. */
    public function deliveredCount(Registration $registration): int
    {
        return count(array_filter(
            $this->statusFor($registration),
            fn (array $d): bool => $d['state'] === 'delivered',
        ));
    }
}
