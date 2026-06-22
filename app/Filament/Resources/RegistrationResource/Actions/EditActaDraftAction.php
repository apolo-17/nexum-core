<?php

namespace App\Filament\Resources\RegistrationResource\Actions;

use App\Enums\DocumentTypeEnum;
use App\Models\Registration;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

/**
 * Header action that opens an edit form for the compiled acta constitutiva draft.
 *
 * Allows the notary team to correct any field in the template_data before the
 * final document is generated. Mirrors Tally's updateRenderData() flow:
 * the compiled data is editable, saved back to ACTA_DRAFT.template_data, and
 * the document can be regenerated as many times as needed.
 *
 * Visible whenever an ACTA_DRAFT with template_data exists on the expedient.
 */
class EditActaDraftAction
{
    /**
     * Build the Filament Action instance for the ViewRegistration header.
     *
     * @param  Registration  $registration  The expedient being viewed.
     */
    public static function make(Registration $registration): Action
    {
        return Action::make('editActaDraft')
            ->label('✏️ Editar borrador del acta')
            ->color('gray')
            ->icon('heroicon-o-pencil-square')
            ->visible(function () use ($registration): bool {
                return $registration->documents()
                    ->where('type', DocumentTypeEnum::ACTA_DRAFT)
                    ->whereNotNull('template_data')
                    ->exists();
            })
            ->modalHeading('Editar datos del borrador del acta')
            ->modalDescription(
                'Corrige los campos que faltan o contienen errores. '
                .'Guarda y usa "Ver borrador" para revisar el resultado.'
            )
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Guardar cambios')
            ->fillForm(function () use ($registration): array {
                $actaDraft = $registration->documents()
                    ->where('type', DocumentTypeEnum::ACTA_DRAFT)
                    ->whereNotNull('template_data')
                    ->latest()
                    ->first();

                $data = $actaDraft->template_data;

                // Normalize socios to a plain indexed array for the Repeater.
                $data['socios'] = array_values($data['socios'] ?? []);

                return $data;
            })
            ->form([
                // ---------------------------------------------------------------
                // Empresa
                // ---------------------------------------------------------------
                Section::make('Datos de la empresa')
                    ->columns(2)
                    ->schema([
                        TextInput::make('autorizacion_denominacion')
                            ->label('Denominación social autorizada')
                            ->required()
                            ->columnSpanFull(),

                        TextInput::make('folio_denominacion')
                            ->label('Folio MUA')
                            ->placeholder('Ej. A202307261053372450'),

                        TextInput::make('fecha_denominacion')
                            ->label('Fecha de autorización MUA')
                            ->placeholder('Ej. 26 (veintiséis) de julio del año 2023'),

                        Select::make('company_type')
                            ->label('Tipo de sociedad')
                            ->options([
                                'SA de CV' => 'SA de CV',
                                'SRL de CV' => 'SRL de CV',
                                'SAPI de CV' => 'SAPI de CV',
                            ])
                            ->required(),

                        TextInput::make('capital_social')
                            ->label('Capital social (MXN)')
                            ->numeric()
                            ->minValue(50000)
                            ->required(),

                        TextInput::make('domicilio_social')
                            ->label('Domicilio social')
                            ->placeholder('Ej. la Ciudad de México')
                            ->columnSpanFull(),

                        Textarea::make('company_activity')
                            ->label('Objeto social')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                // ---------------------------------------------------------------
                // Comisario
                // ---------------------------------------------------------------
                Section::make('Comisario')
                    ->columns(2)
                    ->schema([
                        TextInput::make('comisario')
                            ->label('Nombre del comisario')
                            ->required(),

                        TextInput::make('comisario_rfc')
                            ->label('RFC del comisario')
                            ->required(),
                    ]),

                // ---------------------------------------------------------------
                // Socios — Repeater sin agregar ni eliminar
                // ---------------------------------------------------------------
                Section::make('Socios')
                    ->schema([
                        Repeater::make('socios')
                            ->label('')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->itemLabel(fn (array $state): string => 'Socio '.($state['relay_index'] ?? '?').' — '.($state['socio_nombre'] ?? 'Sin nombre')
                            )
                            ->columns(3)
                            ->schema([
                                // Identidad
                                TextInput::make('socio_nombre')
                                    ->label('Nombre completo')
                                    ->required()
                                    ->columnSpanFull(),

                                TextInput::make('socio_nacionalidad')
                                    ->label('Nacionalidad')
                                    ->placeholder('Ej. chino, china'),

                                Select::make('socio_sexo')
                                    ->label('Sexo')
                                    ->options(['M' => 'Masculino', 'F' => 'Femenino'])
                                    ->required(),

                                Select::make('socio_estado_civil')
                                    ->label('Estado civil')
                                    ->options([
                                        'soltero' => 'Soltero',
                                        'soltera' => 'Soltera',
                                        'casado' => 'Casado',
                                        'casada' => 'Casada',
                                        'divorciado' => 'Divorciado',
                                        'divorciada' => 'Divorciada',
                                        'viudo' => 'Viudo',
                                        'viuda' => 'Viuda',
                                    ]),

                                TextInput::make('socio_regimen_patrimonial')
                                    ->label('Régimen patrimonial')
                                    ->placeholder('Ej. sociedad conyugal'),

                                TextInput::make('socio_ocupacion')
                                    ->label('Ocupación')
                                    ->placeholder('Ej. empresario'),

                                TextInput::make('socio_fecha_nacimiento')
                                    ->label('Fecha de nacimiento')
                                    ->placeholder('Ej. 15 (quince) de marzo de 1985'),

                                TextInput::make('socio_estado_nacimiento')
                                    ->label('Lugar de nacimiento')
                                    ->placeholder('Ej. Shanghái, China'),

                                // Identificación
                                Grid::make(3)
                                    ->columnSpanFull()
                                    ->schema([
                                        Select::make('socio_tipo_identificacion')
                                            ->label('Tipo de identificación')
                                            ->options([
                                                'pasaporte' => 'Pasaporte',
                                                'credencial para votar' => 'Credencial para votar',
                                                'identificación fiscal' => 'Identificación fiscal',
                                            ]),

                                        TextInput::make('socio_tipo_identificacion_numero')
                                            ->label('Número de identificación'),

                                        TextInput::make('socio_identificacion_pais')
                                            ->label('País emisor del documento'),
                                    ]),

                                // Fiscal
                                Grid::make(3)
                                    ->columnSpanFull()
                                    ->schema([
                                        TextInput::make('socio_rfc')
                                            ->label('RFC (genérico extranjero)')
                                            ->placeholder('EXTF900101NI1'),

                                        TextInput::make('socio_curp')
                                            ->label('CURP (genérico extranjero)'),

                                        TextInput::make('tax_type')
                                            ->label('Tipo ID fiscal (país origen)')
                                            ->placeholder('Ej. NIT, TIN, VAT'),

                                        TextInput::make('tax_id')
                                            ->label('Número ID fiscal (país origen)')
                                            ->placeholder('Ej. 91360100400055123X'),
                                    ]),

                                // Domicilio
                                Textarea::make('socio_direccion')
                                    ->label('Domicilio del socio')
                                    ->placeholder('Dirección completa del comprobante de domicilio')
                                    ->rows(2)
                                    ->columnSpanFull(),

                                Grid::make(2)
                                    ->columnSpanFull()
                                    ->schema([
                                        TextInput::make('pais_residencia')
                                            ->label('País de residencia'),

                                        TextInput::make('socio_participacion')
                                            ->label('Participación (%)')
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(100),
                                    ]),
                            ]),
                    ]),
            ])
            ->action(function (array $data) use ($registration): void {
                $actaDraft = $registration->documents()
                    ->where('type', DocumentTypeEnum::ACTA_DRAFT)
                    ->whereNotNull('template_data')
                    ->latest()
                    ->first();

                if (! $actaDraft) {
                    Notification::make()
                        ->title('No se encontró el borrador')
                        ->warning()
                        ->send();

                    return;
                }

                // Merge the edited fields into the existing template_data so that
                // fields not present in the form (compiled_at, metadata, etc.) are kept.
                $existing = $actaDraft->template_data;

                $updated = array_merge($existing, [
                    'autorizacion_denominacion' => strtoupper($data['autorizacion_denominacion']),
                    'folio_denominacion' => $data['folio_denominacion'] ?? $existing['folio_denominacion'],
                    'fecha_denominacion' => $data['fecha_denominacion'] ?? $existing['fecha_denominacion'],
                    'company_type' => $data['company_type'] ?? $existing['company_type'],
                    'capital_social' => (float) ($data['capital_social'] ?? $existing['capital_social']),
                    'domicilio_social' => $data['domicilio_social'] ?? $existing['domicilio_social'],
                    'company_activity' => $data['company_activity'] ?? $existing['company_activity'],
                    'comisario' => strtoupper($data['comisario'] ?? $existing['comisario']),
                    'comisario_rfc' => strtoupper($data['comisario_rfc'] ?? $existing['comisario_rfc']),
                    'socios' => array_values($data['socios'] ?? $existing['socios']),
                ]);

                $actaDraft->update(['template_data' => $updated]);

                Notification::make()
                    ->title('Borrador actualizado')
                    ->body('Los cambios se guardaron. Usa "Ver borrador del acta" para revisar el resultado.')
                    ->success()
                    ->send();
            });
    }
}
