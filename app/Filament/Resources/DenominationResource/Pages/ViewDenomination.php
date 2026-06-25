<?php

namespace App\Filament\Resources\DenominationResource\Pages;

use App\Filament\Resources\DenominationResource;
use App\Models\LegalName;
use App\Services\Mua\MuaStatusCheckService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detail (show) page for a pool denomination.
 *
 * Renders the resource infolist: denomination data, derived timings and the
 * full lifecycle timeline of events (created → submitted → in process → resolved).
 */
class ViewDenomination extends ViewRecord
{
    /**
     * @var class-string<DenominationResource>
     */
    protected static string $resource = DenominationResource::class;

    /**
     * Return the header actions for this page.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->checkStatusAction(),
        ];
    }

    /**
     * Build the "Consultar estado en la SE" action.
     *
     * Visible only for submitted denominations (PENDING/PROCESS) with a FIEL.
     * Dispatches an asynchronous status-check request to the MUA bot; the result
     * arrives later via the callback and appears on the timeline. The page polls
     * so the "Consultando…" indicator clears on its own when the result lands.
     */
    private function checkStatusAction(): Action
    {
        return Action::make('check_status')
            ->label('Consultar estado en la SE')
            ->icon('heroicon-o-magnifying-glass-circle')
            ->color('info')
            ->visible(fn (): bool => $this->record instanceof LegalName && $this->record->canRequestStatusCheck())
            ->disabled(fn (): bool => $this->record instanceof LegalName && $this->record->isAwaitingCheckResult())
            ->requiresConfirmation()
            ->modalDescription('Se pedirá al bot que consulte el estado de esta denominación en el portal de la SE. El resultado aparecerá en el historial cuando responda.')
            ->action(function (): void {
                /** @var LegalName $record */
                $record = $this->record;

                try {
                    app(MuaStatusCheckService::class)->requestCheck($record);
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('No se pudo enviar la consulta al bot.')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Consulta enviada al bot.')
                    ->body('El resultado aparecerá en el historial cuando la SE responda.')
                    ->success()
                    ->send();
            });
    }
}
