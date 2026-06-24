<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Enums\DocumentTypeEnum;
use App\Filament\Resources\RegistrationResource;
use App\Models\Document;
use App\Models\Registration;
use App\Services\Registration\GenerateActaDocxService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Storage;

/**
 * Inline editor page for the acta constitutiva draft.
 *
 * Renders the compiled acta template in editable mode — injected field values
 * are highlighted by data source (MUA, AI extraction, socio data, manual) and
 * are directly editable via contenteditable spans in the browser. Alpine.js
 * collects all dirty spans on save and dispatches to saveFields().
 *
 * This page also hosts docx generation (generateDocx()) so the notary can
 * review, edit, and download the acta without ever leaving this page —
 * implementing the Propuesta B UX pattern (clean header, everything in the editor).
 *
 * Keyboard shortcuts: Ctrl+S / Cmd+S → save inline edits.
 */
class EditActaInlinePage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = RegistrationResource::class;

    protected string $view = 'filament.pages.edit-acta-inline';

    /**
     * The compiled template_data array currently loaded into the editor.
     *
     * @var array<string, mixed>
     */
    public array $templateData = [];

    /**
     * Resolve the record and load the most recent ACTA_DRAFT template_data.
     *
     * @param  int|string  $record  Route-bound registration key.
     */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $actaDraft = $this->getActaDraft();

        abort_unless($actaDraft !== null, 404, 'No existe un borrador del acta para este expediente.');

        $this->templateData = $actaDraft->template_data ?? [];
    }

    /**
     * Persist inline field edits back into the ACTA_DRAFT template_data.
     *
     * Receives a flat dot-notation key-value map from Alpine.js — e.g.
     * {'autorizacion_denominacion': 'NOVA CONSULTORA SA DE CV', 'socios.0.socio_rfc': 'XYZZ860101ABC'}.
     * Each key is walked with data_set() to support nested socio paths.
     * After updating the document, the in-page templateData property is
     * refreshed so subsequent saves are always based on the latest state.
     *
     * @param  array<string, string>  $fields  Dot-notation key → trimmed string value map.
     */
    public function saveFields(array $fields): void
    {
        $doc = $this->getActaDraft();

        abort_unless($doc !== null, 404, 'Borrador no encontrado.');

        /** @var array<string, mixed> $data */
        $data = $doc->template_data ?? [];

        foreach ($fields as $dotKey => $value) {
            data_set($data, $dotKey, trim((string) $value));
        }

        $doc->update(['template_data' => $data]);

        // Keep in-memory state in sync so Livewire re-renders the updated values.
        $this->templateData = $data;

        Notification::make()
            ->title('Cambios guardados')
            ->body('El borrador del acta se actualizó correctamente.')
            ->success()
            ->send();
    }

    /**
     * Generate the final .docx acta constitutiva from the current ACTA_DRAFT template_data.
     *
     * Calls GenerateActaDocxService to fill the sa.docx template, uploads the result
     * to R2, and dispatches a browser event with a 15-minute presigned download URL.
     * The download opens in a new tab via Alpine's @open-download-url.window listener
     * defined in the edit-acta-inline Blade view.
     *
     * @throws \Throwable Caught internally; shown as a danger notification on failure.
     */
    public function generateDocx(): void
    {
        /** @var GenerateActaDocxService $service */
        $service = resolve(GenerateActaDocxService::class);

        try {
            $actaFinal = $service->generate($this->getRecord());

            // Generate a 15-minute presigned URL so the notary can download immediately.
            $downloadUrl = Storage::disk('s3')->temporaryUrl(
                $actaFinal->storage_path,
                now()->addMinutes(15),
            );

            // Dispatch to the browser — Alpine listens with @open-download-url.window.
            $this->dispatch('open-download-url', url: $downloadUrl);

            Notification::make()
                ->title('Acta generada')
                ->body('El archivo .docx fue generado. La descarga comenzará en un momento.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error al generar el .docx')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Fetch the most recent ACTA_DRAFT document with template_data for this expedient.
     *
     * @return Document|null Null when no compiled draft exists yet.
     */
    protected function getActaDraft(): ?Document
    {
        return $this->getRecord()
            ->documents()
            ->where('type', DocumentTypeEnum::ACTA_DRAFT)
            ->whereNotNull('template_data')
            ->latest()
            ->first();
    }

    /**
     * Page heading shown in the Filament panel title bar.
     */
    public function getHeading(): string
    {
        /** @var Registration $reg */
        $reg = $this->getRecord();

        return "Acta constitutiva — {$reg->singapur_client_code}";
    }

    /**
     * Breadcrumb label shown in the Filament breadcrumb trail.
     */
    public function getBreadcrumb(): string
    {
        return 'Revisar acta';
    }
}
