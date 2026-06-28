<?php

namespace App\Filament\Resources\RegistrationResource\Actions;

use App\Models\Registration;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;

/**
 * Filament action to upload and safekeep the company's own credentials.
 *
 * Stores the incorporated company's e.firma (FIEL) files (.cer/.key), the e.firma
 * password and the RFC document (Constancia de Situación Fiscal) for retrieval and
 * download. Files go to the default filesystem disk (R2 in production); the password
 * is reversibly encrypted via the model's `encrypted` cast.
 *
 * This is independent of the e.firma SAT appointment flow (ConfirmEfirmaOutcomeAction):
 * the company credentials are not used to operate procedures yet, only safeguarded.
 * Every field is optional — only the provided values are updated, so the form can be
 * used incrementally (e.g. upload the .cer today, the .key later).
 */
class ManageCompanyCredentialsAction extends Action
{
    /**
     * Build and return the configured action instance.
     */
    public static function make(?string $name = null): static
    {
        return parent::make($name ?? 'manage_company_credentials')
            ->label('Credenciales de la empresa')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->modalHeading('Credenciales de la empresa (FIEL + RFC)')
            ->modalDescription('Resguardo del .cer, .key, contraseña y RFC de la empresa. Sólo se reemplazan los campos que cargues.')
            ->fillForm(fn (Registration $record): array => [
                'company_fiel_password' => $record->company_fiel_password,
            ])
            ->form(fn (Schema $schema): Schema => $schema->components([
                FileUpload::make('company_fiel_cer_file')
                    ->label('Certificado .cer')
                    ->acceptedFileTypes(['application/octet-stream', 'application/pkix-cert'])
                    ->disk(config('filesystems.default'))
                    ->directory('company-credentials')
                    ->visibility('private'),

                FileUpload::make('company_fiel_key_file')
                    ->label('Llave privada .key')
                    ->acceptedFileTypes(['application/octet-stream'])
                    ->disk(config('filesystems.default'))
                    ->directory('company-credentials')
                    ->visibility('private'),

                TextInput::make('company_fiel_password')
                    ->label('Contraseña de la e.firma')
                    ->password()
                    ->revealable()
                    ->maxLength(255),

                FileUpload::make('company_rfc_file')
                    ->label('RFC / Constancia de Situación Fiscal (PDF)')
                    ->acceptedFileTypes(['application/pdf'])
                    ->disk(config('filesystems.default'))
                    ->directory('company-credentials')
                    ->visibility('private'),
            ]))
            ->action(function (Registration $record, array $data): void {
                $payload = [];

                // Only persist fields the user actually provided, preserving existing values.
                if (filled($data['company_fiel_cer_file'] ?? null)) {
                    $payload['company_fiel_cer_path'] = $data['company_fiel_cer_file'];
                }

                if (filled($data['company_fiel_key_file'] ?? null)) {
                    $payload['company_fiel_key_path'] = $data['company_fiel_key_file'];
                }

                if (filled($data['company_rfc_file'] ?? null)) {
                    $payload['company_rfc_path'] = $data['company_rfc_file'];
                }

                if (filled($data['company_fiel_password'] ?? null)) {
                    // Plain value assigned — the model's `encrypted` cast handles encryption.
                    $payload['company_fiel_password'] = $data['company_fiel_password'];
                }

                if ($payload === []) {
                    Notification::make()
                        ->title('Sin cambios')
                        ->body('No se cargó ningún archivo ni contraseña.')
                        ->warning()
                        ->send();

                    return;
                }

                $record->update($payload);

                Notification::make()
                    ->title('Credenciales guardadas')
                    ->body('Las credenciales de la empresa fueron resguardadas correctamente.')
                    ->success()
                    ->send();
            });
    }
}
