<?php

declare(strict_types=1);

namespace App\Services\Denomination;

use App\Enums\DocumentTypeEnum;
use App\Enums\LegalNameEventTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Models\Document;
use App\Models\LegalName;
use App\Models\Registration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Links an SE-approved pool denomination to a registration expedient.
 *
 * Pool names are pre-approved with no expedient attached; their constancia PDF is
 * stored by the MUA bot callback under `denominations/pool/`. Claiming a name both
 * assigns it to the registration as its priority-1 denomination and copies that
 * constancia into the expedient as a LEGAL_NAME_AUTHORIZATION document, so the
 * notary sees the SE authorization alongside the rest of the file.
 *
 * Used by the dashboard (registration form) and by the China/Singapur claim API.
 */
class ClaimPoolDenominationService
{
    /**
     * Disk holding both the pool constancias and the expedient documents (R2).
     */
    private const DISK = 's3';

    /**
     * Link an available pool denomination to a registration as its primary name.
     *
     * The link is committed first (row-locked re-check guards against a double
     * claim from the API and the dashboard at once); the constancia is copied
     * afterwards so a storage hiccup never rolls back — or blocks — the claim.
     *
     * @param  LegalName  $legalName  The pool denomination to claim.
     * @param  Registration  $registration  The expedient that will own the name.
     * @return PoolClaimResult Whether the name was claimed and the constancia attached.
     */
    public function claim(LegalName $legalName, Registration $registration): PoolClaimResult
    {
        $claimed = DB::transaction(function () use ($legalName, $registration): bool {
            /** @var LegalName|null $fresh */
            $fresh = LegalName::whereKey($legalName->getKey())->lockForUpdate()->first();

            // Still available? (approved by the SE and not yet assigned)
            if ($fresh === null
                || $fresh->registration_id !== null
                || $fresh->status !== LegalNameStatusEnum::APPROVED) {
                return false;
            }

            $fresh->update([
                'registration_id' => $registration->id,
                'priority' => $this->reservePrimaryPriority($registration),
            ]);

            return true;
        });

        if (! $claimed) {
            return new PoolClaimResult(
                claimed: false,
                reason: 'La denominación ya no está disponible: fue asignada a otro expediente o cambió de estatus.',
            );
        }

        $legalName->refresh();

        $legalName->recordEvent(
            LegalNameEventTypeEnum::CLAIMED,
            "Asignada al expediente {$registration->singapur_client_code}.",
            [
                'registration_id' => $registration->id,
                'registration_code' => $registration->singapur_client_code,
            ],
        );

        return $this->attachConstancia($legalName, $registration);
    }

    /**
     * Copy the pool constancia PDF into the expedient and register it as a document.
     *
     * Failures here are contained: the name is already claimed, so the operator is
     * told the constancia is missing (and can upload it by hand) instead of seeing
     * the whole operation blow up.
     *
     * @param  LegalName  $legalName  The just-claimed denomination.
     * @param  Registration  $registration  The owning expedient.
     * @return PoolClaimResult Claim result carrying the constancia outcome.
     */
    private function attachConstancia(LegalName $legalName, Registration $registration): PoolClaimResult
    {
        $source = self::poolConstanciaPath($legalName);

        try {
            $disk = Storage::disk(self::DISK);

            if (! $disk->exists($source)) {
                return new PoolClaimResult(
                    claimed: true,
                    reason: 'La denominación no tiene constancia guardada en el pool; súbela a mano en Documentos.',
                );
            }

            $target = "registrations/{$registration->id}/constancia_denominacion_{$legalName->id}.pdf";

            // Copy (not move): the pool object stays as the untouched original the
            // MUA bot wrote, so a re-claim after an undo still finds the PDF.
            $disk->put($target, $disk->get($source));

            Document::updateOrCreate(
                [
                    'registration_id' => $registration->id,
                    'type' => DocumentTypeEnum::LEGAL_NAME_AUTHORIZATION->value,
                ],
                [
                    'storage_path' => $target,
                    'name' => "Constancia de denominación — {$legalName->name}",
                    'stage' => RegistrationStageEnum::LEGAL_NAME->value,
                ],
            );

            $legalName->recordEvent(
                LegalNameEventTypeEnum::CONSTANCIA_RECEIVED,
                'Constancia adjuntada al expediente.',
                ['storage_path' => $target],
            );

            return new PoolClaimResult(claimed: true, constanciaAttached: true);
        } catch (\Throwable $exception) {
            Log::error('Pool denomination claimed but the constancia could not be attached.', [
                'legal_name_id' => $legalName->id,
                'registration_id' => $registration->id,
                'source' => $source,
                'exception' => $exception->getMessage(),
            ]);

            return new PoolClaimResult(
                claimed: true,
                reason: 'La denominación quedó vinculada, pero no se pudo copiar la constancia: '
                    .$exception->getMessage(),
            );
        }
    }

    /**
     * Free up priority 1 for the incoming denomination.
     *
     * The priority-1 row is what the dashboard title, the acta and the SAT payload
     * read, so the claimed (SE-approved) name must take it. Any placeholder already
     * sitting there is demoted rather than deleted — proposals are audit material.
     *
     * @param  Registration  $registration  The expedient receiving the name.
     * @return int The priority the claimed denomination should take (always 1).
     */
    private function reservePrimaryPriority(Registration $registration): int
    {
        $current = $registration->legalNames()->where('priority', 1)->first();

        if ($current !== null) {
            $maxPriority = (int) $registration->legalNames()->max('priority');

            $current->update(['priority' => $maxPriority + 1]);
        }

        return 1;
    }

    /**
     * Build the pool storage path where the MUA bot saved this name's constancia.
     *
     * Mirrors MuaBotCallbackController::approvePoolDenomination(); the path is
     * derived from the denomination id, no column stores it.
     *
     * @param  LegalName  $legalName  The pool denomination.
     * @return string Path on the S3/R2 disk.
     */
    public static function poolConstanciaPath(LegalName $legalName): string
    {
        return "denominations/pool/constancia_denominacion_{$legalName->id}.pdf";
    }
}
