<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Enums\DocumentTypeEnum;
use App\Filament\Resources\RegistrationResource;
use App\Filament\Resources\RegistrationResource\Actions\AdvanceStageAction;
use App\Filament\Resources\RegistrationResource\Actions\EditActaInlineAction;
use App\Filament\Resources\RegistrationResource\Actions\ManageCompanyCredentialsAction;
use App\Filament\Resources\RegistrationResource\Actions\PartnerSignatureAction;
use App\Filament\Resources\RegistrationResource\Actions\PrepareActaAction;
use App\Jobs\BuildActaRenderJob;
use App\Jobs\ExtractActaPartiesJob;
use App\Models\Registration;
use App\Services\DocuSign\DocuSignService;
use App\Services\Registration\ActaPreparationService;
use App\Services\Registration\StageTransitionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Displays the full detail view of a registration expedient including all relation managers.
 *
 * Header actions follow the Propuesta B UX pattern — maximum two visible actions:
 *   - ACTA_PREPARATION (no draft yet) → PrepareActaAction + AdvanceStageAction
 *   - Any stage with a draft          → EditActaInlineAction ("Revisar acta") + AdvanceStageAction
 *   - EFIRMA_APPOINTMENT              → e.firma appointment actions + AdvanceStageAction
 *
 * Docx generation and draft field editing have been moved into the inline editor page
 * to keep the header clean and lawyer-friendly.
 */
class ViewRegistration extends ViewRecord
{
    protected static string $resource = RegistrationResource::class;

    /**
     * Return the header actions available on the view page.
     *
     * Visible action matrix:
     *   - ACTA_PREPARATION  → PrepareActaAction (compile/refresh the draft)
     *   - Draft exists       → EditActaInlineAction (full-page editor; includes download)     *   - All stages         → AdvanceStageAction
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var Registration $record */
        $record = $this->record;

        // El partner es solo lectura: ninguna acción de encabezado (acta, etapa, e.firma,
        // credenciales, editar). Solo consulta el expediente y descarga documentos.
        if (auth()->user()?->isPartner() ?? false) {
            return [];
        }

        return [
            // Generar / reintentar el acta en segundo plano. Corre las validaciones,
            // asigna apoderados si faltan y arma el borrador; avisa por correo al terminar
            // (lista o incompleta con lo que falta). Útil cuando faltaba un dato y ya se corrigió.
            Action::make('regenerateActaRender')
                ->label('Generar / reintentar acta')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Generar el acta')
                ->modalDescription('Se generará el acta en segundo plano con los datos y apoderados del expediente. Puede tardar unos minutos; te avisaremos por correo cuando esté lista, o si falta algún dato.')
                ->modalSubmitActionLabel('Generar acta')
                ->action(function () use ($record): void {
                    BuildActaRenderJob::dispatch($record->id);

                    Notification::make()
                        ->title('Generando el acta…')
                        ->body('Esto puede tardar unos minutos. Cuando esté lista te avisaremos por correo.')
                        ->info()
                        ->send();
                }),

            // Extraer con IA los socios/apoderados del acta protocolizada y ligar al expediente
            // (por RFC) los apoderados que sí tenemos. Útil para empresas a las que solo se les
            // subió el acta: recupera de ahí sus representantes legales para poder sacar citas.
            Action::make('extractActaParties')
                ->label('Extraer socios del acta (IA)')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn (): bool => $record->documents()
                    ->where('type', DocumentTypeEnum::ACTA_PROTOCOLIZADA)
                    ->whereNotNull('storage_path')
                    ->exists())
                ->requiresConfirmation()
                ->modalHeading('Leer el acta protocolizada con IA')
                ->modalDescription('Se leerá el acta con IA para extraer los socios y apoderados, verificar que sea un acta protocolizada y ligar al expediente (por RFC) los apoderados que ya tenemos. Tarda un par de minutos; te avisamos en la campana.')
                ->modalSubmitActionLabel('Extraer')
                ->action(function () use ($record): void {
                    ExtractActaPartiesJob::dispatch($record->id);

                    Notification::make()
                        ->title('Analizando el acta…')
                        ->body('Te avisamos en la campana cuando termine (un par de minutos).')
                        ->info()
                        ->send();
                }),

            // "Revisar acta" — navigates to the full inline editor.
            // Visible whenever a compiled ACTA_DRAFT with template_data exists.
            // Download (.docx) is available from inside the editor toolbar.
            EditActaInlineAction::make(registration: $record),

            // Acta draft compilation — visible only at ACTA_PREPARATION stage (no draft yet).
            PrepareActaAction::make(
                registration: $record,
                actaPreparationService: resolve(ActaPreparationService::class),
            ),

            // DocuSign — send acta for electronic signature (PARTNER_SIGNATURE stage).
            PartnerSignatureAction::make(
                registration: $record,
                docuSignService: resolve(DocuSignService::class),
            ),

            // Stage-advance action — general workflow progression.
            AdvanceStageAction::make(
                registration: $record,
                performedBy: auth()->user(),
                stageTransitionService: resolve(StageTransitionService::class),
            ),

            // Safeguard the company's own e.firma + RFC for download (any stage).
            ManageCompanyCredentialsAction::make(),

            // Enviar/reenviar entregables a China: en el panel "Entregables a China" del expediente.

            EditAction::make(),
        ];
    }
}
