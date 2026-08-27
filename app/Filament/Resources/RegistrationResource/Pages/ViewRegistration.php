<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Filament\Resources\RegistrationResource;
use App\Filament\Resources\RegistrationResource\Actions\AdvanceStageAction;
use App\Filament\Resources\RegistrationResource\Actions\EditActaInlineAction;
use App\Filament\Resources\RegistrationResource\Actions\ManageCompanyCredentialsAction;
use App\Filament\Resources\RegistrationResource\Actions\PartnerSignatureAction;
use App\Filament\Resources\RegistrationResource\Actions\PrepareActaAction;
use App\Jobs\BuildActaRenderJob;
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

            // Enviar a China todos los entregables que tenemos pero aún no están confirmados
            // (los que se cargaron antes de que el relay funcionara, o que fallaron/rechazaron).
            Action::make('sendPendingToChina')
                ->label('Enviar pendientes a China')
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->visible(fn (): bool => self::pendingChinaDocuments($record)->isNotEmpty())
                ->requiresConfirmation()
                ->modalHeading('Enviar pendientes a China')
                ->modalDescription(fn (): string => 'Se enviarán '.self::pendingChinaDocuments($record)->count()
                    .' documento(s) a China en segundo plano. Recibirás una alerta por cada uno.')
                ->modalSubmitActionLabel('Enviar todos')
                ->action(function () use ($record): void {
                    $docs = self::pendingChinaDocuments($record);

                    foreach ($docs as $doc) {
                        $doc->forceFill([
                            'relay_delivered_at' => null,
                            'relay_drive_url' => null,
                            'relay_rejected_at' => null,
                            'relay_rejection_reason' => null,
                        ])->saveQuietly();

                        \App\Jobs\NotifyRelayDocumentJob::dispatch($doc->id);
                    }

                    Notification::make()
                        ->title('Enviando a China…')
                        ->body($docs->count().' documento(s) en camino. Te avisaremos por cada uno.')
                        ->info()
                        ->send();
                }),

            EditAction::make(),
        ];
    }

    /**
     * Deliverable documents this registration HAS but China has not confirmed yet.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Document>
     */
    private static function pendingChinaDocuments(Registration $record): \Illuminate\Support\Collection
    {
        return \App\Models\Document::query()
            ->where('registration_id', $record->id)
            ->whereIn('type', array_keys(\App\Services\Singapur\ChinaDeliverablesService::DELIVERABLES))
            ->whereNotNull('storage_path')
            ->whereNull('relay_delivered_at')
            ->get();
    }
}
