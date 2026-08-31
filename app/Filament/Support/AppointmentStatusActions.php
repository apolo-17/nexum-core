<?php

namespace App\Filament\Support;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Models\Appointment;
use App\Services\Sat\SatReviewService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Status-change actions for a SAT appointment, shared so the same controls appear both in the
 * expediente (AppointmentsRelationManager) and on the dashboard board (SatAppointmentsBoard) —
 * no duplicated action code. Only the self-contained status transitions live here; the ones with
 * heavier side effects (attend-with-CSF, cancel-with-email-cooldown) stay in the expediente.
 */
class AppointmentStatusActions
{
    /**
     * The status actions as a compact dropdown, ready to drop into ->recordActions([...]).
     */
    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            self::reviewNow(),
            self::markFormed(),
            self::markRejected(),
            self::markNoShow(),
        ])
            ->label('Actualizar estado')
            ->icon('heroicon-m-ellipsis-vertical')
            ->tooltip('Actualizar estado de la cita')
            ->button();
    }

    /** Ask the SAT bot to check, live, whether a formed cita already has a date. */
    public static function reviewNow(): Action
    {
        return Action::make('reviewNow')
            ->label('Revisar status ahora')
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::FORMED)
            ->modalHeading('Revisar el status en el SAT')
            ->modalDescription('Voy a consultar el SAT en vivo para ver si ya te asignaron fecha. Tarda unos segundos.')
            ->modalSubmitActionLabel('Revisar ahora')
            ->action(function (Appointment $record): void {
                $result = app(SatReviewService::class)->reviewNow($record);
                $record->refresh();

                Notification::make()
                    ->title(match ($result['status'] ?? 'error') {
                        'scheduled' => '¡Ya tienes fecha!',
                        'in_review' => 'Sigue en espera',
                        default => 'No se pudo revisar',
                    })
                    ->body($result['message'] ?? 'Sin detalle.')
                    ->status(match ($result['status'] ?? 'error') {
                        'scheduled' => 'success',
                        'in_review' => 'info',
                        default => 'danger',
                    })
                    ->send();
            });
    }

    /** Mark a still-to-form cita as formed by hand (someone formed it at the SAT portal). */
    public static function markFormed(): Action
    {
        return Action::make('markFormed')
            ->label('Marcar formada (a mano)')
            ->icon('heroicon-o-check-circle')
            ->color('warning')
            ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::PENDING_FORMING)
            ->requiresConfirmation()
            ->modalDescription('Úsalo solo si TÚ formaste la cita en el portal del SAT.')
            ->action(function (Appointment $record): void {
                $record->update([
                    'status' => AppointmentStatusEnum::FORMED,
                    'formed_at' => now(),
                ]);
                $record->recordEvent(
                    AppointmentEventTypeEnum::MARKED_MANUALLY,
                    'Alguien del equipo la marcó formada a mano; el bot la revisa desde aquí.',
                    ['email_alias' => $record->email_alias],
                    'user',
                );
                Notification::make()->title('Marcada como formada')->success()->send();
            });
    }

    /** Mark a scheduled cita as rejected by the SAT, capturing the reason. */
    public static function markRejected(): Action
    {
        return Action::make('markRejected')
            ->label('Rechazada por el SAT')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::SCHEDULED)
            ->modalDescription('El SAT rechazó el trámite. Anota el motivo (por qué lo rechazaron); luego saca una nueva cita de RFC.')
            ->form([
                Textarea::make('rejection_reason')
                    ->label('Motivo del rechazo')
                    ->required()
                    ->rows(3)
                    ->placeholder('Ej.: faltó un documento, el poder no tenía facultad fiscal, la e.firma no estaba activa…'),
            ])
            ->action(function (Appointment $record, array $data): void {
                $motivo = trim((string) ($data['rejection_reason'] ?? ''));
                $record->update([
                    'status' => AppointmentStatusEnum::REJECTED,
                    'rejection_reason' => $motivo,
                ]);
                $record->recordEvent(
                    AppointmentEventTypeEnum::REJECTED,
                    'El SAT rechazó el trámite en la cita. Motivo: '.$motivo,
                    ['rejection_reason' => $motivo],
                    'user',
                );
                Notification::make()->title('Marcada como rechazada')->warning()->send();
            });
    }

    /** Mark a scheduled cita as a no-show (the soldado did not attend). */
    public static function markNoShow(): Action
    {
        return Action::make('markNoShow')
            ->label('No asistió')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::SCHEDULED)
            ->requiresConfirmation()
            ->action(function (Appointment $record): void {
                $record->update(['status' => AppointmentStatusEnum::NO_SHOW]);
                $record->recordEvent(AppointmentEventTypeEnum::NO_SHOW, 'El soldado no asistió a la cita.', [], 'user');
                Notification::make()->title('Marcada como no asistió')->warning()->send();
            });
    }
}
