<?php

namespace App\Filament\Resources\RegistrationResource\Actions;

use App\Enums\EfirmaAppointmentStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Models\Registration;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Filament action that requests a new e.firma appointment with the SAT bot.
 *
 * Available only when the registration is at the EFIRMA_APPOINTMENT stage and
 * either has no appointment yet or the previous one was rejected / a no-show.
 * Sets the status to PENDING_SCHEDULING so the admin knows the request was sent.
 */
class RequestEfirmaAppointmentAction extends Action
{
    /**
     * Build and return the configured action instance.
     *
     * @return static
     */
    public static function make(?string $name = null): static
    {
        return parent::make($name ?? 'request_efirma_appointment')
            ->label('Solicitar cita e.firma')
            ->icon('heroicon-o-calendar-days')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Solicitar cita con el SAT')
            ->modalDescription('Se enviará la solicitud al bot del SAT para agendar una cita de e.firma. ¿Confirmas?')
            ->modalSubmitActionLabel('Sí, solicitar cita')
            ->visible(function (Registration $record): bool {
                if ($record->stage !== RegistrationStageEnum::EFIRMA_APPOINTMENT) {
                    return false;
                }

                // Show when no appointment has been requested yet or rescheduling is needed.
                if ($record->efirma_status === null) {
                    return true;
                }

                return $record->efirma_status->requiresRescheduling();
            })
            ->action(function (Registration $record): void {
                $record->update([
                    'efirma_status'         => EfirmaAppointmentStatusEnum::PENDING_SCHEDULING,
                    'efirma_appointment_at' => null,
                ]);

                Notification::make()
                    ->title('Cita solicitada')
                    ->body('La solicitud fue enviada al bot del SAT. La fecha será confirmada pronto.')
                    ->success()
                    ->send();
            });
    }
}
