<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Filament\Resources\RegistrationResource;
use App\Filament\Resources\RegistrationResource\Actions\AdvanceStageAction;
use App\Services\Registration\StageTransitionService;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Displays the full detail view of a registration expedient including all relation managers.
 *
 * Provides a header action for the notary to advance the expedient through stages.
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
            AdvanceStageAction::make(
                registration:          $record,
                performedBy:           auth()->user(),
                stageTransitionService: resolve(StageTransitionService::class),
            ),
            EditAction::make(),
        ];
    }
}
