<?php

namespace App\Filament\Resources\RegistrationResource\Actions;

use App\Enums\DocumentTypeEnum;
use App\Models\Registration;
use App\Services\Registration\GenerateActaDocxService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Header action that generates the final .docx acta constitutiva.
 *
 * Calls GenerateActaDocxService to fill sa.docx with the ACTA_DRAFT template_data,
 * upload the result to R2, and create an ACTA_FINAL document record. After generation
 * the action shows a temporary download link via a success notification.
 *
 * Visible whenever an ACTA_DRAFT with template_data exists on the expedient.
 */
class GenerateActaDocxAction
{
    /**
     * Build the Filament Action for the ViewRegistration header.
     *
     * @param  Registration  $registration  The expedient being viewed.
     * @param  GenerateActaDocxService  $service  Injected generation service.
     */
    public static function make(
        Registration $registration,
        GenerateActaDocxService $service,
    ): Action {
        return Action::make('generateActaDocx')
            ->label('📄 Generar .docx')
            ->color('success')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(function () use ($registration): bool {
                return $registration->documents()
                    ->where('type', DocumentTypeEnum::ACTA_DRAFT)
                    ->whereNotNull('template_data')
                    ->exists();
            })
            ->requiresConfirmation()
            ->modalHeading('Generar acta constitutiva (.docx)')
            ->modalDescription(
                'Se generará el documento Word con los datos del borrador actual. '
                .'Si ya existe un .docx previo será reemplazado. ¿Continuar?'
            )
            ->modalSubmitActionLabel('Sí, generar')
            ->action(function () use ($registration, $service): void {
                try {
                    $actaFinal = $service->generate($registration);

                    // Generate a short-lived signed URL (15 minutes) so the notary
                    // can download the file immediately from R2.
                    $downloadUrl = Storage::disk('s3')->temporaryUrl(
                        $actaFinal->storage_path,
                        now()->addMinutes(15),
                    );

                    Notification::make()
                        ->title('Acta generada correctamente')
                        ->body(
                            'El archivo .docx fue creado. '
                            .'<a href="'.e($downloadUrl).'" target="_blank" '
                            .'style="text-decoration:underline;font-weight:600;">'
                            .'Descargar ahora</a> (el enlace expira en 15 minutos).'
                        )
                        ->success()
                        ->persistent()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Error al generar el .docx')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
