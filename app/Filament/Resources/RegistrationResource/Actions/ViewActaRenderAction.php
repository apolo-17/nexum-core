<?php

namespace App\Filament\Resources\RegistrationResource\Actions;

use App\Enums\DocumentTypeEnum;
use App\Models\Registration;
use Filament\Actions\Action;

/**
 * Header action that displays the acta constitutiva as a rendered legal document.
 *
 * Reads the template_data stored in the existing ACTA_DRAFT document and passes
 * it to the render-document blade view, which presents the full acta constitutiva
 * with all clauses, the shareholders table, and the signature block.
 *
 * This action is read-only — it does not recompile the data. To refresh the data
 * the notary must use PrepareActaAction again. Visible whenever an ACTA_DRAFT
 * document with template_data exists on the expedient (any stage).
 */
class ViewActaRenderAction
{
    /**
     * Build the Filament Action instance for the ViewRegistration header.
     *
     * @param  Registration  $registration  The expedient being viewed.
     */
    public static function make(Registration $registration): Action
    {
        return Action::make('viewActaRender')
            ->label('👁 Ver borrador del acta')
            ->color('info')
            ->icon('heroicon-o-document-magnifying-glass')
            // Visible whenever a saved ACTA_DRAFT exists — any stage after ACTA_PREPARATION.
            ->visible(function () use ($registration): bool {
                return $registration->documents()
                    ->where('type', DocumentTypeEnum::ACTA_DRAFT)
                    ->whereNotNull('template_data')
                    ->exists();
            })
            ->modalHeading(
                fn (): string => "Borrador del Acta Constitutiva — {$registration->singapur_client_code}"
            )
            ->modalDescription(
                'Vista previa del documento que se enviará a firma digital. '
                .'Si los datos están incompletos, usa "Preparar borrador del acta" para regenerarlo.'
            )
            ->modalWidth('5xl')
            // No submit button — this is a read-only preview.
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->modalContent(function () use ($registration) {
                // Load from the saved ACTA_DRAFT — no recompilation needed.
                $actaDraft = $registration->documents()
                    ->where('type', DocumentTypeEnum::ACTA_DRAFT)
                    ->whereNotNull('template_data')
                    ->latest()
                    ->first();

                return view('filament.acta.render-document', [
                    'data' => $actaDraft->template_data,
                    'registration' => $registration,
                ]);
            });
    }
}
