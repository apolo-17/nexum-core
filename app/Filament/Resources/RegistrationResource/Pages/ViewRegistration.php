<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Filament\Resources\RegistrationResource;
use App\Filament\Resources\RegistrationResource\Actions\AdvanceStageAction;
use App\Filament\Resources\RegistrationResource\Actions\ConfirmEfirmaOutcomeAction;
use App\Filament\Resources\RegistrationResource\Actions\EditActaInlineAction;
use App\Filament\Resources\RegistrationResource\Actions\PartnerSignatureAction;
use App\Filament\Resources\RegistrationResource\Actions\PrepareActaAction;
use App\Filament\Resources\RegistrationResource\Actions\RequestEfirmaAppointmentAction;
use App\Models\Registration;
use App\Services\DocuSign\DocuSignService;
use App\Services\Registration\ActaPreparationService;
use App\Services\Registration\StageTransitionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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
     *   - Draft exists       → EditActaInlineAction (full-page editor; includes download)
     *   - EFIRMA_APPOINTMENT → RequestEfirmaAppointmentAction, ConfirmEfirmaOutcomeAction
     *   - All stages         → AdvanceStageAction
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var Registration $record */
        $record = $this->record;

        return [
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

            // e.firma appointment actions — visible only at EFIRMA_APPOINTMENT stage.
            RequestEfirmaAppointmentAction::make(),
            ConfirmEfirmaOutcomeAction::make(),

            EditAction::make(),
        ];
    }
}
