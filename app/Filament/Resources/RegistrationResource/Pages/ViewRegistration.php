<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Filament\Resources\RegistrationResource;
use App\Filament\Resources\RegistrationResource\Actions\AdvanceStageAction;
use App\Filament\Resources\RegistrationResource\Actions\ConfirmEfirmaOutcomeAction;
use App\Filament\Resources\RegistrationResource\Actions\PrepareActaAction;
use App\Filament\Resources\RegistrationResource\Actions\RequestEfirmaAppointmentAction;
use App\Models\Registration;
use App\Services\Registration\ActaPreparationService;
use App\Services\Registration\StageTransitionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Displays the full detail view of a registration expedient including all relation managers.
 *
 * Header actions adapt to the current stage:
 *   - ACTA_PREPARATION  → PrepareActaAction (compile draft) + AdvanceStageAction
 *   - EFIRMA_APPOINTMENT → e.firma appointment actions
 *   - All others         → AdvanceStageAction
 */
class ViewRegistration extends ViewRecord
{
    protected static string $resource = RegistrationResource::class;

    /**
     * Return the header actions available on the view page.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var Registration $record */
        $record = $this->record;

        return [
            // Acta draft compilation — visible only at ACTA_PREPARATION stage.
            PrepareActaAction::make(
                registration: $record,
                actaPreparationService: resolve(ActaPreparationService::class),
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
