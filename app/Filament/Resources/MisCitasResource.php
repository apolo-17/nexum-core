<?php

namespace App\Filament\Resources;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Services\Registration\StageTransitionService;
use App\Enums\DocumentTypeEnum;
use App\Filament\Resources\MisCitasResource\Pages;
use App\Jobs\FormSatAppointmentJob;
use App\Models\Appointment;
use App\Models\Document;
use App\Services\Document\DocumentAnalysisService;
use App\Services\Efirma\EfirmaCredentialValidator;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only resource showing the logged-in soldado their own SAT appointments.
 *
 * Scoped to appointments assigned to the soldado linked to the current user. Makes
 * the two-appointments-per-company rule clear (RFC and FIEL) and which are completed.
 */
class MisCitasResource extends Resource
{
    /**
     * @var class-string<Appointment>
     */
    protected static ?string $model = Appointment::class;

    protected static ?string $navigationLabel = 'Mis citas';

    protected static ?string $modelLabel = 'Cita';

    protected static ?string $pluralModelLabel = 'Mis citas';

    protected static string|\UnitEnum|null $navigationGroup = 'Mi panel';

    protected static ?int $navigationSort = 1;

    /**
     * Return the icon for this resource in the sidebar.
     */
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-calendar-days';
    }

    /**
     * Restrict access to soldados only.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('soldado') ?? false;
    }

    /**
     * This is a read-only view for soldados — no creation.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Scope to appointments assigned to the current user's soldado profile.
     *
     * @return Builder<Appointment>
     */
    public static function getEloquentQuery(): Builder
    {
        $soldadoId = Auth::user()?->soldado?->id;

        return parent::getEloquentQuery()
            ->where('soldado_id', $soldadoId ?? '')
            ->with('registration.primaryLegalName');
    }

    /**
     * Define the table of the soldado's appointments.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registration.primaryLegalName.name')
                    ->label('Empresa')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (AppointmentTypeEnum $state): string => $state->label())
                    ->color(fn (AppointmentTypeEnum $state): string => $state->color()),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (AppointmentStatusEnum $state): string => $state->label())
                    ->color(fn (AppointmentStatusEnum $state): string => $state->color()),

                TextColumn::make('scheduled_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sin agendar')
                    ->sortable(),

                TextColumn::make('office')
                    ->label('Sede')
                    ->placeholder('—'),

                TextColumn::make('acknowledgment_path')
                    ->label('Documentos')
                    ->badge()
                    // Un solo botón descarga acuse + comprobante de domicilio (ZIP).
                    ->state(fn (Appointment $record): string => self::hasDownloadableDocs($record) ? 'Descargar' : '—')
                    ->color(fn (Appointment $record): string => self::hasDownloadableDocs($record) ? 'success' : 'gray')
                    ->url(fn (Appointment $record): ?string => self::hasDownloadableDocs($record)
                        ? route('admin.appointments.documents.download', ['appointment' => $record])
                        : null)
                    ->openUrlInNewTab(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(AppointmentTypeEnum::options()),
            ])
            ->recordActions([
                Action::make('reportar')
                    ->label('Reportar / actualizar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->button()
                    // Aparece solo en la cita de RFC cuando ya pasó (y sigue agendada).
                    ->visible(fn (Appointment $record): bool => $record->type === AppointmentTypeEnum::RFC
                        && $record->status === AppointmentStatusEnum::SCHEDULED
                        && self::yaPaso($record))
                    ->modalHeading(fn (Appointment $record): string => 'Tu cita de '
                        .($record->registration?->primaryLegalName?->name ?? 'la empresa'))
                    ->modalSubmitActionLabel('Guardar')
                    ->form([
                        Select::make('resultado')
                            ->label('¿Cómo te fue en tu cita?')
                            ->options([
                                'attended' => '✅ Salió bien',
                                'rejected' => '❌ Me rechazaron',
                                'no_show' => '🚫 No asistí',
                            ])
                            ->required()
                            ->native(false)
                            ->live(),

                        Radio::make('modo')
                            ->label('¿Tienes tu Constancia de Situación Fiscal (CSF)?')
                            ->options([
                                'foto' => 'Sí — subo foto o PDF',
                                'manual' => 'No — escribo el RFC a mano',
                            ])
                            ->default('foto')
                            ->live()
                            ->visible(fn (Get $get): bool => $get('resultado') === 'attended'),

                        FileUpload::make('csf')
                            ->label('Foto o PDF de la CSF')
                            ->helperText('Formatos: JPG, PNG, WEBP o PDF. En iPhone el formato HEIC no se puede leer — toma la foto y sube como JPG, o escribe el RFC a mano.')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->disk('s3')
                            ->directory('documents/csf')
                            ->maxSize(12288)
                            ->live()
                            ->visible(fn (Get $get): bool => $get('resultado') === 'attended' && $get('modo') === 'foto')
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $file = is_array($state) ? reset($state) : $state;

                                if (! is_object($file) || ! method_exists($file, 'getRealPath')) {
                                    return;
                                }

                                try {
                                    $bytes = file_get_contents($file->getRealPath());
                                    $mime = $file->getMimeType() ?: 'image/jpeg';
                                    $fields = app(DocumentAnalysisService::class)
                                        ->extractFields(base64_encode($bytes), $mime, DocumentTypeEnum::CSF);
                                    $rfc = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($fields['rfc'] ?? '')));

                                    if ($rfc !== '') {
                                        $set('rfc', $rfc);
                                        Notification::make()->title("Leímos el RFC: {$rfc}")->body('Revísalo y corrige si algo no cuadra.')->success()->send();
                                    } else {
                                        Notification::make()->title('No pudimos leer el RFC')->body('Escríbelo a mano abajo.')->warning()->send();
                                    }
                                } catch (\Throwable $e) {
                                    Notification::make()->title('No se pudo leer la imagen')->body('Escribe el RFC a mano abajo.')->warning()->send();
                                }
                            }),

                        TextInput::make('rfc')
                            ->label('RFC de la empresa')
                            ->helperText('12 caracteres. Revísalo contra tu documento.')
                            ->maxLength(13)
                            ->extraInputAttributes(['style' => 'text-transform:uppercase;letter-spacing:2px;font-weight:700'])
                            ->visible(fn (Get $get): bool => $get('resultado') === 'attended')
                            ->required(fn (Get $get): bool => $get('resultado') === 'attended'),

                        Toggle::make('ir_efirma')
                            ->label('¿Vas a ir también a la cita de e.firma de esta empresa?')
                            ->helperText('Si dices que sí, la formamos automáticamente con este RFC.')
                            ->default(true)
                            ->visible(fn (Get $get): bool => $get('resultado') === 'attended'),
                    ])
                    ->action(fn (Appointment $record, array $data) => self::procesarReporte($record, $data)),

                Action::make('subirEfirma')
                    ->label('Subir e.firma')
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->button()
                    // Aparece en la cita de e.firma (FIEL) cuando ya pasó (agendada) O cuando ya
                    // se marcó como asistida: la e.firma se sube DESPUÉS de la cita, así que marcar
                    // éxito no debe esconder el botón — se puede subir/reintentar mientras esté attended.
                    ->visible(fn (Appointment $record): bool => $record->type === AppointmentTypeEnum::FIEL
                        && (
                            $record->status === AppointmentStatusEnum::ATTENDED
                            || ($record->status === AppointmentStatusEnum::SCHEDULED && self::yaPaso($record))
                        ))
                    ->modalHeading(fn (Appointment $record): string => 'e.firma de '
                        .($record->registration?->primaryLegalName?->name ?? 'la empresa'))
                    ->modalDescription('Sube los archivos de la e.firma de la empresa. Validamos que sean correctos antes de guardarlos.')
                    ->modalSubmitActionLabel('Validar y guardar')
                    ->form([
                        Select::make('resultado')
                            ->label('¿Cómo te fue en tu cita de e.firma?')
                            ->options([
                                'attended' => '✅ Salió bien, ya tengo la e.firma',
                                'rejected' => '❌ Me rechazaron',
                                'no_show' => '🚫 No asistí',
                            ])
                            ->required()
                            ->native(false)
                            ->live()
                            // Si la cita ya se marcó asistida, precarga "salió bien" para que los
                            // campos de la e.firma aparezcan de una vez y solo falte subir archivos.
                            ->default(fn (Appointment $record): ?string => $record->status === AppointmentStatusEnum::ATTENDED ? 'attended' : null),

                        FileUpload::make('cer_file')
                            ->label('Certificado (.cer)')
                            ->helperText('El archivo .cer que te entregó el SAT.')
                            ->disk('s3')
                            ->directory('company-credentials')
                            ->maxSize(2048)
                            ->visible(fn (Get $get): bool => $get('resultado') === 'attended')
                            ->required(fn (Get $get): bool => $get('resultado') === 'attended'),

                        FileUpload::make('key_file')
                            ->label('Llave privada (.key)')
                            ->helperText('El archivo .key que va con tu e.firma.')
                            ->disk('s3')
                            ->directory('company-credentials')
                            ->maxSize(2048)
                            ->visible(fn (Get $get): bool => $get('resultado') === 'attended')
                            ->required(fn (Get $get): bool => $get('resultado') === 'attended'),

                        TextInput::make('password')
                            ->label('Contraseña de la e.firma')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('resultado') === 'attended')
                            ->required(fn (Get $get): bool => $get('resultado') === 'attended'),

                        FileUpload::make('req_file')
                            ->label('Requerimiento (.req) — opcional')
                            ->helperText('Si lo tienes, súbelo para dejar el expediente de e.firma completo.')
                            ->disk('s3')
                            ->directory('company-credentials')
                            ->maxSize(2048)
                            ->visible(fn (Get $get): bool => $get('resultado') === 'attended'),
                    ])
                    ->action(fn (Appointment $record, array $data) => self::procesarEfirma($record, $data)),
            ])
            ->recordUrl(fn (Appointment $record): string => Pages\ViewMiCita::getUrl(['record' => $record]))
            ->defaultSort('scheduled_at', 'desc');
    }

    /**
     * scheduled_at guarda la hora local de CDMX; se reinterpreta en CDMX y se comprueba
     * que ya pasaron al menos 30 minutos desde la cita.
     */
    private static function yaPaso(Appointment $record): bool
    {
        if ($record->scheduled_at === null) {
            return false;
        }

        return Carbon::parse($record->scheduled_at->format('Y-m-d H:i:s'), 'America/Mexico_City')
            ->addMinutes(30)->isPast();
    }

    /**
     * Aplica lo que reportó el soldado: marca el resultado y, si asistió, guarda la CSF y
     * el RFC de la empresa, y forma la cita de e.firma si el soldado va a ir.
     *
     * @param  array<string, mixed>  $data
     */
    private static function procesarReporte(Appointment $record, array $data): void
    {
        $registration = $record->registration;
        $resultado = (string) ($data['resultado'] ?? '');

        if ($resultado === 'rejected') {
            $record->update(['status' => AppointmentStatusEnum::REJECTED]);
            $record->recordEvent(AppointmentEventTypeEnum::REJECTED, 'El soldado reportó que el SAT rechazó el trámite.', [], 'soldado');
            self::avisarAdmins($registration, "El soldado reportó su cita de RFC como RECHAZADA.");
            Notification::make()->title('Registrado')->body('Gracias por avisar. El equipo sacará una nueva cita.')->success()->send();

            return;
        }

        if ($resultado === 'no_show') {
            $record->update(['status' => AppointmentStatusEnum::NO_SHOW]);
            $record->recordEvent(AppointmentEventTypeEnum::NO_SHOW, 'El soldado reportó que no asistió.', [], 'soldado');
            self::avisarAdmins($registration, 'El soldado reportó que NO asistió a su cita de RFC.');
            Notification::make()->title('Registrado')->body('Gracias por avisar.')->success()->send();

            return;
        }

        // Asistió: guardar RFC (+ CSF si la subió) y marcar la cita.
        $rfc = strtoupper(trim((string) ($data['rfc'] ?? '')));

        if (filled($data['csf'] ?? null) && $registration !== null) {
            $path = is_array($data['csf']) ? reset($data['csf']) : $data['csf'];

            $csf = Document::create([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::CSF,
                'name' => 'CSF '.($registration->primaryLegalName?->name ?? 'empresa'),
                'storage_path' => $path,
                'stage' => $registration->getRawOriginal('stage'),
                'verified_at' => now(),
            ]);

            // Extraer en segundo plano el RFC y el domicilio fiscal del CSF.
            \App\Jobs\ExtractCsfDataJob::dispatch($csf->id)->afterCommit();
        }

        $registration?->update(['rfc' => $rfc]);
        $record->update(['status' => AppointmentStatusEnum::ATTENDED]);
        $record->recordEvent(AppointmentEventTypeEnum::ATTENDED, "El soldado completó la cita. RFC: {$rfc}.", ['rfc' => $rfc], 'soldado');

        // El RFC ya está: el expediente avanza a "Registro SAT".
        self::avanzarEtapa($registration, RegistrationStageEnum::SAT_REGISTRATION);

        // Preparar la cita de e.firma (por formar) con el mismo soldado, si no existe.
        $fiel = $registration?->appointments()
            ->where('type', AppointmentTypeEnum::FIEL->value)
            ->whereNotIn('status', [AppointmentStatusEnum::CANCELLED->value, AppointmentStatusEnum::REJECTED->value])
            ->first();

        if ($fiel === null && $registration !== null) {
            $fiel = $registration->appointments()->create([
                'type' => AppointmentTypeEnum::FIEL->value,
                'status' => AppointmentStatusEnum::PENDING_FORMING->value,
                'soldado_id' => $record->soldado_id,
            ]);
        }

        if (($data['ir_efirma'] ?? false) && $fiel !== null) {
            if ($record->soldado_id !== null && $registration !== null
                && ! $registration->soldados()->where('soldados.id', $record->soldado_id)->exists()) {
                $registration->soldados()->attach($record->soldado_id, ['role' => 'legal_representative']);
            }

            FormSatAppointmentJob::dispatch($fiel->id);
            self::avisarAdmins($registration, "El soldado completó la cita de RFC (RFC {$rfc}) y va a la e.firma — se está formando.");
            Notification::make()->title('¡Listo! 🚀')->body('Guardamos tu RFC y ya se está formando tu cita de e.firma.')->success()->send();
        } else {
            self::avisarAdmins($registration, "El soldado completó la cita de RFC. RFC capturado: {$rfc}.");
            Notification::make()->title('¡Listo! ✅')->body('Guardamos tu RFC y la CSF.')->success()->send();
        }
    }

    /**
     * Procesa la subida de la e.firma en la cita FIEL: valida el .cer/.key/contraseña con
     * OpenSSL (que sean pareja, contraseña correcta, vigente y del RFC de la empresa) y,
     * si pasa, resguarda cer/key/req/contraseña de la empresa. Si no pasa, avisa el motivo
     * y NO guarda (el soldado puede reintentar).
     *
     * @param  array<string, mixed>  $data
     */
    private static function procesarEfirma(Appointment $record, array $data): void
    {
        $registration = $record->registration;
        $resultado = (string) ($data['resultado'] ?? '');

        if ($resultado === 'rejected') {
            $record->update(['status' => AppointmentStatusEnum::REJECTED]);
            $record->recordEvent(AppointmentEventTypeEnum::REJECTED, 'El soldado reportó que el SAT rechazó la e.firma.', [], 'soldado');
            self::avisarAdmins($registration, 'El soldado reportó su cita de e.firma como RECHAZADA.');
            Notification::make()->title('Registrado')->body('Gracias por avisar. El equipo sacará una nueva cita.')->success()->send();

            return;
        }

        if ($resultado === 'no_show') {
            $record->update(['status' => AppointmentStatusEnum::NO_SHOW]);
            $record->recordEvent(AppointmentEventTypeEnum::NO_SHOW, 'El soldado reportó que no asistió a la e.firma.', [], 'soldado');
            self::avisarAdmins($registration, 'El soldado reportó que NO asistió a su cita de e.firma.');
            Notification::make()->title('Registrado')->body('Gracias por avisar.')->success()->send();

            return;
        }

        // Asistió: validar la e.firma antes de resguardarla.
        $disk = Storage::disk('s3');
        $cerPath = self::firstPath($data['cer_file'] ?? null);
        $keyPath = self::firstPath($data['key_file'] ?? null);
        $reqPath = self::firstPath($data['req_file'] ?? null);
        $password = (string) ($data['password'] ?? '');

        $cerBytes = $cerPath !== null ? (string) $disk->get($cerPath) : '';
        $keyBytes = $keyPath !== null ? (string) $disk->get($keyPath) : '';

        $result = app(EfirmaCredentialValidator::class)->validate(
            $cerBytes,
            $keyBytes,
            $password,
            $registration?->rfc,
        );

        if (! $result->valid) {
            // Borra los archivos recién subidos para no dejar credenciales inválidas.
            foreach ([$cerPath, $keyPath, $reqPath] as $path) {
                if ($path !== null) {
                    $disk->delete($path);
                }
            }

            Notification::make()
                ->title('La e.firma no pasó la validación')
                ->body(implode(' ', $result->errors))
                ->danger()
                ->persistent()
                ->send();

            return; // No marca la cita: el soldado puede corregir y reintentar.
        }

        // Válida: resguardar cer/key/req/contraseña de la empresa (la contraseña se cifra por cast).
        $registration?->update([
            'company_fiel_cer_path' => $cerPath,
            'company_fiel_key_path' => $keyPath,
            'company_fiel_req_path' => $reqPath ?? $registration->company_fiel_req_path,
            'company_fiel_password' => $password,
        ]);

        $record->update(['status' => AppointmentStatusEnum::ATTENDED]);
        $record->recordEvent(
            AppointmentEventTypeEnum::ATTENDED,
            'El soldado subió y validó la e.firma de la empresa (RFC '.($result->rfc ?? '—').').',
            ['rfc' => $result->rfc],
            'soldado',
        );
        // La e.firma quedó resguardada: el expediente avanza a "Cita e.firma".
        self::avanzarEtapa($registration, RegistrationStageEnum::EFIRMA_APPOINTMENT);

        self::avisarAdmins($registration, 'El soldado completó la e.firma y subió la FIEL validada de la empresa'.($result->rfc !== null ? " (RFC {$result->rfc})" : '').'.');

        Notification::make()
            ->title('¡e.firma validada y guardada! 🔐')
            ->body('Tus archivos pasaron todas las validaciones y quedaron resguardados.')
            ->success()
            ->send();
    }

    /**
     * Normaliza el valor de un FileUpload (array o string) a una ruta única o null.
     */
    private static function firstPath(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return filled($value) ? (string) $value : null;
    }

    /**
     * Avanza automáticamente la etapa del expediente cuando un hito del SAT se cumple
     * (RFC obtenido → Registro SAT; e.firma resguardada → Cita e.firma). Solo avanza
     * hacia adelante (jumpTo es forward-only) y nunca rompe el flujo si algo falla.
     */
    private static function avanzarEtapa(?\App\Models\Registration $registration, RegistrationStageEnum $destino): void
    {
        if ($registration === null) {
            return;
        }

        try {
            app(StageTransitionService::class)->jumpTo(
                $registration->fresh(),
                $destino,
                null,
                'Avance automático: hito del SAT cumplido.',
            );
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('MisCitas: no se pudo avanzar la etapa automáticamente.', [
                'registration_id' => $registration->id,
                'target' => $destino->value,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private static function avisarAdmins(?\App\Models\Registration $registration, string $body): void
    {
        try {
            $admins = \App\Models\User::role('super_admin')->get();
            if ($admins->isNotEmpty()) {
                $empresa = $registration?->primaryLegalName?->name ?? 'una empresa';
                \Illuminate\Support\Facades\Notification::send($admins, new \App\Notifications\SoldadoCitaUpdateNotification($empresa, $body));
            }
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * Define the resource pages — list only (read-only).
     *
     * @return array<string, PageRegistration>
     */
    /**
     * Whether the cita has anything to download: the acuse or the comprobante de domicilio.
     */
    private static function hasDownloadableDocs(Appointment $record): bool
    {
        if (filled($record->acknowledgment_path)) {
            return true;
        }

        return (bool) $record->registration?->documents()
            ->where('type', \App\Enums\DocumentTypeEnum::PROOF_OF_ADDRESS_MX->value)
            ->whereNotNull('storage_path')
            ->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMisCitas::route('/'),
            'view' => Pages\ViewMiCita::route('/{record}'),
        ];
    }
}
