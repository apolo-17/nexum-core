<?php

namespace App\Filament\Resources\RegistrationResource\Actions;

use App\Enums\EfirmaAppointmentStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Models\Registration;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

/**
 * Filament action for the admin to confirm the outcome of an e.firma SAT appointment.
 *
 * Three outcomes are handled:
 *  - attended_approved: client attended and the e.firma was issued. Admin uploads
 *    the .key and .cer files plus the password, which is stored hashed.
 *  - attended_rejected: client attended but SAT rejected the appointment.
 *  - no_show: client did not attend the scheduled appointment.
 *
 * Only visible when the registration is at EFIRMA_APPOINTMENT stage and the
 * appointment status is SCHEDULED (date confirmed by the bot).
 */
class ConfirmEfirmaOutcomeAction extends Action
{
    /**
     * Build and return the configured action instance.
     *
     * @return static
     */
    public static function make(?string $name = null): static
    {
        return parent::make($name ?? 'confirm_efirma_outcome')
            ->label('Confirmar resultado de cita')
            ->icon('heroicon-o-check-badge')
            ->color('primary')
            ->visible(fn (Registration $record): bool => (
                $record->stage === RegistrationStageEnum::EFIRMA_APPOINTMENT
                && $record->efirma_status === EfirmaAppointmentStatusEnum::SCHEDULED
            ))
            ->form(fn (Schema $schema): Schema => $schema->components([
                Radio::make('outcome')
                    ->label('¿Qué ocurrió en la cita?')
                    ->options([
                        EfirmaAppointmentStatusEnum::ATTENDED_APPROVED->value => '✅ Asistió y fue aprobado — e.firma emitida',
                        EfirmaAppointmentStatusEnum::ATTENDED_REJECTED->value => '❌ Asistió pero fue rechazado por el SAT',
                        EfirmaAppointmentStatusEnum::NO_SHOW->value           => '⚠️  No asistió a la cita',
                    ])
                    ->required()
                    ->live(),

                FileUpload::make('efirma_key_file')
                    ->label('Archivo .key (llave privada)')
                    ->acceptedFileTypes(['application/octet-stream'])
                    ->disk('private')
                    ->directory('efirma/keys')
                    ->visibility('private')
                    ->required()
                    ->visible(fn (Get $get): bool => (
                        $get('outcome') === EfirmaAppointmentStatusEnum::ATTENDED_APPROVED->value
                    )),

                FileUpload::make('efirma_cer_file')
                    ->label('Archivo .cer (certificado)')
                    ->acceptedFileTypes(['application/octet-stream', 'application/pkix-cert'])
                    ->disk('private')
                    ->directory('efirma/certs')
                    ->visibility('private')
                    ->required()
                    ->visible(fn (Get $get): bool => (
                        $get('outcome') === EfirmaAppointmentStatusEnum::ATTENDED_APPROVED->value
                    )),

                TextInput::make('efirma_password')
                    ->label('Contraseña de la e.firma')
                    ->password()
                    ->revealable()
                    ->required()
                    ->visible(fn (Get $get): bool => (
                        $get('outcome') === EfirmaAppointmentStatusEnum::ATTENDED_APPROVED->value
                    )),
            ]))
            ->action(function (Registration $record, array $data): void {
                $outcome = EfirmaAppointmentStatusEnum::from($data['outcome']);

                if ($outcome === EfirmaAppointmentStatusEnum::ATTENDED_APPROVED) {
                    $record->update([
                        'efirma_status'        => $outcome,
                        'efirma_key_path'      => $data['efirma_key_file'],
                        'efirma_cer_path'      => $data['efirma_cer_file'],
                        // Store hashed — the plain-text password is never persisted.
                        'efirma_password_hash' => Hash::make($data['efirma_password']),
                    ]);

                    Notification::make()
                        ->title('e.firma registrada')
                        ->body('Los archivos y credenciales fueron guardados. El expediente puede avanzar a completado.')
                        ->success()
                        ->send();
                } else {
                    // Rejected or no-show — record outcome; a new appointment request is needed.
                    $record->update(['efirma_status' => $outcome]);

                    Notification::make()
                        ->title('Resultado registrado')
                        ->body($outcome === EfirmaAppointmentStatusEnum::ATTENDED_REJECTED
                            ? 'SAT rechazó la cita. Solicita una nueva cita para continuar.'
                            : 'El cliente no asistió. Solicita una nueva cita para continuar.')
                        ->warning()
                        ->send();
                }
            });
    }
}
