<?php

namespace App\Filament\Resources\SoldadoResource\Pages;

use App\Filament\Resources\SoldadoResource;
use App\Models\Soldado;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detail (show) page for a soldado.
 */
class ViewSoldado extends ViewRecord
{
    /**
     * @var class-string<SoldadoResource>
     */
    protected static string $resource = SoldadoResource::class;

    /**
     * Return the header actions for this page.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('grantAccess')
                ->label('Dar acceso al panel')
                ->icon('heroicon-o-envelope')
                ->color('success')
                ->visible(fn (Soldado $record): bool => $record->user_id === null)
                ->requiresConfirmation()
                ->modalDescription('Se creará una cuenta y se enviará un correo de bienvenida para que el soldado defina su contraseña.')
                ->action(function (Soldado $record): void {
                    try {
                        SoldadoResource::grantAccess($record);

                        Notification::make()
                            ->title('Invitación enviada')
                            ->success()
                            ->send();
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('No se pudo enviar la invitación')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            EditAction::make(),
        ];
    }
}
