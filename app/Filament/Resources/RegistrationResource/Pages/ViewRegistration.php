<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Filament\Resources\RegistrationResource;
use App\Filament\Resources\RegistrationResource\Actions\AdvanceStageAction;
use App\Filament\Resources\RegistrationResource\Actions\ConfirmEfirmaOutcomeAction;
use App\Filament\Resources\RegistrationResource\Actions\RequestEfirmaAppointmentAction;
use App\Services\Registration\StageTransitionService;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Displays the full detail view of a registration expedient including all relation managers.
 *
 * Header actions adapt to the current stage: at EFIRMA_APPOINTMENT the standard
 * advance button is replaced by stage-specific e.firma appointment actions.
 */
class ViewRegistration extends ViewRecord
{
    protected static string $resource = RegistrationResource::class;

    /**
     * Return the header actions available on the view page.
     *
     * @return array<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var \App\Models\Registration $record */
        $record = $this->record;

        return [
            // Stage-advance action — general workflow progression.
            AdvanceStageAction::make(
                registration:           $record,
                performedBy:            auth()->user(),
                stageTransitionService: resolve(StageTransitionService::class),
            ),

            // e.firma appointment actions — visible only at EFIRMA_APPOINTMENT stage.
            RequestEfirmaAppointmentAction::make(),
            ConfirmEfirmaOutcomeAction::make(),

            EditAction::make(),
        ];
    }
}
