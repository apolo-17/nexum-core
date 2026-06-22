<?php

namespace App\Filament\Resources\RegistrationResource\Actions;

use App\Enums\DocumentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Services\Registration\ActaPreparationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Header action that compiles and saves the acta constitutiva draft.
 *
 * Visible only at the ACTA_PREPARATION stage. When triggered it calls
 * ActaPreparationService to aggregate all available data (relay fields,
 * Claude vision extractions, hardcoded defaults) into a structured JSON
 * block, then persists it as an ACTA_DRAFT document on the expedient.
 *
 * The notary team can review the compiled data in the modal before confirming,
 * and can re-run the action after correcting any shareholder fields manually.
 *
 * The resulting ACTA_DRAFT document with its template_data will be used later
 * to generate the final DOCX/PDF before sending to DocuSign.
 */
class PrepareActaAction
{
    /**
     * Build the Filament Action instance for the ViewRegistration header.
     *
     * Uses a static factory method so ViewRegistration can call
     * PrepareActaAction::make() consistently with other header actions.
     *
     * @param  Registration  $registration  The expedient being viewed.
     * @param  ActaPreparationService  $actaPreparationService  Injected service.
     */
    public static function make(
        Registration $registration,
        ActaPreparationService $actaPreparationService,
    ): Action {
        return Action::make('prepareActa')
            ->label('📋 Preparar borrador del acta')
            ->color('warning')
            ->icon('heroicon-o-document-text')
            // Only relevant at the ACTA_PREPARATION stage.
            ->visible(fn (): bool => $registration->stage === RegistrationStageEnum::ACTA_PREPARATION)
            ->modalHeading('Revisar datos para el Acta Constitutiva')
            ->modalDescription(
                'Se compilarán todos los datos disponibles (webhook, extracción IA, valores por defecto). '
                .'Revisa que la información sea correcta antes de continuar. '
                .'Si algún campo falta, corrígelo en el expediente y vuelve a generar.'
            )
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Guardar borrador del acta')
            ->modalContent(function () use ($registration, $actaPreparationService) {
                $data = $actaPreparationService->compile($registration);

                return view('filament.acta.preview-modal', [
                    'data' => $data,
                    'registration' => $registration,
                ]);
            })
            ->action(function () use ($registration, $actaPreparationService): void {
                $data = $actaPreparationService->compile($registration);

                // Upsert the ACTA_DRAFT document — idempotent so the notary
                // can regenerate after correcting any field.
                Document::updateOrCreate(
                    [
                        'registration_id' => $registration->id,
                        'type' => DocumentTypeEnum::ACTA_DRAFT,
                    ],
                    [
                        'name' => "Borrador Acta - {$registration->singapur_client_code}",
                        'template_data' => $data,
                        'stage' => $registration->stage,
                        'uploaded_by' => Auth::id(),
                    ],
                );

                Notification::make()
                    ->title('Borrador del acta guardado')
                    ->body(
                        'Los datos compilados se guardaron como borrador. '
                        .'Puedes verlos en la pestaña Documentos del expediente. '
                        .'Confirma la etapa cuando el borrador esté listo para firma.'
                    )
                    ->success()
                    ->send();
            });
    }
}
