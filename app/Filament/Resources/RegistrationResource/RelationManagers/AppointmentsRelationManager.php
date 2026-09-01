<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Enums\NotificationEventEnum;
use App\Filament\Support\AppointmentStatusActions;
use App\Jobs\FormSatAppointmentJob;
use App\Models\Appointment;
use App\Models\AppointmentEmail;
use App\Models\SatModule;
use App\Notifications\SatAppointmentCancelledNotification;
use App\Notifications\SatAppointmentStatusNotification;
use App\Services\Notifications\EventNotifier;
use App\Services\Registration\RfcCaptureService;
use App\Services\Registration\SatShareholderRelationService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry as InfoTextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Manages the SAT appointments (RFC and FIEL) for a company.
 *
 * Lifecycle: the team FORMS the appointment manually at the SAT portal and marks it
 * "formada" (choosing the pool email used to receive the token). From there the
 * nexum-citas-sat bot reviews the formed ones and, when the SAT assigns a slot, fills
 * the date/office/acuse via the callback (→ "agendada").
 */
class AppointmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'appointments';

    protected static ?string $title = 'Citas SAT (RFC y e.firma)';

    /**
     * Allow mutations even when rendered inside a ViewRecord page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Timezone used to display stored (UTC) timestamps: the SAT operates in CDMX.
     */
    private const TIMEZONE = 'America/Mexico_City';

    /**
     * Human-readable office: the bot stores the SAT module id, not its name.
     *
     * @param  Appointment  $record  The appointment whose office is shown.
     */
    /**
     * Active statuses that occupy a SAT slot: a soldado in any of these for a given cita
     * type cannot be given another cita of the same type.
     */
    private const ACTIVE_STATUSES = [
        AppointmentStatusEnum::PENDING_FORMING->value,
        AppointmentStatusEnum::FORMED->value,
        AppointmentStatusEnum::SCHEDULED->value,
    ];

    /**
     * True when the soldado already has an active cita of the given type (excluding the one
     * being edited). The SAT allows a soldado at most one active cita per type — one RFC and
     * one e.firma at the same time, never two of the same.
     */
    private static function soldadoHasActiveConflict(?string $soldadoId, ?string $type, ?string $exceptId): bool
    {
        if (blank($soldadoId) || blank($type)) {
            return false;
        }

        // La regla "una sola cita activa por tipo" aplica SOLO a las citas de INSCRIPCIÓN DE RFC:
        // esas se tramitan con el RFC del SOLDADO, así que el SAT no le permite dos a la vez.
        // Las citas de e.firma (FIEL) salen con el RFC de la EMPRESA (no del soldado), por lo que
        // un mismo soldado sí puede tener varias citas de e.firma al mismo tiempo.
        if ($type !== AppointmentTypeEnum::RFC->value) {
            return false;
        }

        return Appointment::query()
            ->where('soldado_id', $soldadoId)
            ->where('type', $type)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    private static function typeLabel(?string $type): string
    {
        return AppointmentTypeEnum::tryFrom((string) $type)?->label() ?? 'ese tipo';
    }

    private static function officeName(Appointment $record): string
    {
        $office = (string) ($record->office ?? '');

        if ($office === '') {
            return '';
        }

        // El bot guarda el id numérico del módulo; tradúcelo al nombre del catálogo.
        if (ctype_digit($office)) {
            return SatModule::where('sat_id', (int) $office)->value('name') ?? $office;
        }

        return $office;
    }

    /**
     * Define the form schema for creating and editing appointments.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Tipo de cita')
                ->options(AppointmentTypeEnum::options())
                ->required()
                ->live()
                ->helperText('Cada empresa necesita una cita RFC y una cita e.firma (FIEL).')
                // La e.firma se tramita con el RFC de la EMPRESA (persona moral). No se puede
                // agendar hasta que la empresa ya tenga su RFC (que sale de la cita de inscripción
                // de RFC). Sin RFC moral, se bloquea con un mensaje claro.
                ->rules([
                    fn ($livewire): Closure => function (string $attribute, mixed $value, Closure $fail) use ($livewire): void {
                        if ($value !== AppointmentTypeEnum::FIEL->value) {
                            return;
                        }

                        $registration = $livewire->getOwnerRecord();
                        if (blank($registration->rfc)) {
                            $empresa = $registration->primaryLegalName?->name
                                ?? $registration->singapur_folder_name
                                ?? 'esta empresa';
                            $fail("No se puede agendar la cita de e.firma de {$empresa}: todavía no tiene su RFC moral. "
                                .'Primero hay que completar la cita de inscripción de RFC para obtener el RFC de la empresa.');
                        }
                    },
                ]),

            Select::make('status')
                ->label('Estado')
                ->options(AppointmentStatusEnum::options())
                ->default(AppointmentStatusEnum::PENDING_FORMING->value)
                ->required(),

            Select::make('soldado_id')
                ->label('Soldado que asiste')
                // Solo apoderados del acta de ESTA empresa que además: (a) tengan luz verde
                // como representante (available_as_legal_representative) — estar en el acta no
                // basta, deben poder ir al SAT; y (b) tengan RFC (los identifica ante el SAT)
                // y correo (para avisarles). La CURP no la pide el SAT para la cita.
                // En citas de e.firma se muestra la vigencia de la FIEL del soldado (aviso):
                // el SAT solo lo admite si su FIEL personal está vigente (caso Ulises).
                ->options(function (Get $get, $livewire): array {
                    $reps = $livewire->getOwnerRecord()
                        ->legalRepresentatives()
                        ->where('available_as_legal_representative', true)
                        ->whereNotNull('rfc')
                        ->whereNotNull('email')
                        ->orderBy('name')
                        ->get();

                    $isFiel = $get('type') === AppointmentTypeEnum::FIEL->value;

                    return $reps->mapWithKeys(function ($soldado) use ($isFiel): array {
                        $label = $soldado->name;
                        if ($isFiel) {
                            $label .= $soldado->fielVigente()
                                ? '  ·  FIEL vigente ✓'
                                : '  ·  FIEL vencida/sin fecha ⛔';
                        }

                        return [$soldado->id => $label];
                    })->all();
                })
                ->helperText('Apoderados del acta con luz verde, RFC y correo. En e.firma se marca la vigencia de la FIEL del soldado; solo super admin puede cambiarlo en citas de e.firma.')
                ->searchable()
                ->live()
                // Solo super admin cambia el soldado en citas de e.firma (FIEL): al día de la
                // cita se puede necesitar cambiarlo por uno con FIEL vigente.
                ->disabled(fn (Get $get): bool => $get('type') === AppointmentTypeEnum::FIEL->value
                    && ! (auth()->user()?->hasRole('super_admin') ?? false))
                // Alerta INMEDIATA al seleccionar: un soldado no puede tener dos citas del
                // mismo tipo al mismo tiempo (el SAT no lo permite). Puede tener una RFC y
                // una e.firma a la vez, pero no dos RFC ni dos e.firma.
                ->afterStateUpdated(function ($state, Get $get, ?Appointment $record): void {
                    if (self::soldadoHasActiveConflict($state, $get('type'), $record?->id)) {
                        Notification::make()
                            ->title('⚠️ Soldado ocupado')
                            ->body('Este soldado ya tiene una cita de '.self::typeLabel($get('type'))
                                .' activa. El SAT no permitirá asignarle otra del mismo tipo hasta que la termine o se cancele.')
                            ->warning()
                            ->persistent()
                            ->send();
                    }
                })
                // Y bloqueo al guardar, por si se cambió el tipo después de elegir el soldado.
                ->rules([
                    fn (Get $get, ?Appointment $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                        if (self::soldadoHasActiveConflict($value, $get('type'), $record?->id)) {
                            $fail('Este soldado ya tiene una cita de '.self::typeLabel($get('type'))
                                .' activa. El SAT no permite dos citas del mismo tipo para el mismo soldado.');
                        }
                    },
                ]),

            // Sin toggle: al guardar una cita "por formar" con soldado, se manda a formar
            // automáticamente (ver autoDispatchForming). El equipo no tiene que hacer nada.

            Select::make('preferred_module')
                ->label('Sucursal del SAT donde formar')
                ->options(fn (): array => SatModule::options())
                ->searchable()
                ->helperText('Opcional. Si la dejas vacía, el bot elige entre las sucursales de CDMX.'),

            // El correo del pool lo elige el bot solo al formar (assignAlias toma uno libre).
            // Ya no se escoge a mano aquí.

            DateTimePicker::make('formed_at')
                ->label('Fecha en que se formó (fila virtual)')
                ->native(false),

            DateTimePicker::make('scheduled_at')
                ->label('Fecha/hora asignada por el SAT')
                ->native(false),

            TextInput::make('office')
                ->label('Sucursal / módulo del SAT')
                ->maxLength(255),

            FileUpload::make('acknowledgment_path')
                ->label('Acuse de la cita')
                ->disk(config('filesystems.default'))
                ->directory('appointments/acuses')
                ->visibility('private')
                ->maxSize(4096),

            Textarea::make('notes')
                ->label('Notas')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Define the table of appointments for this company.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                BadgeColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (AppointmentTypeEnum $state): string => $state->label())
                    ->color(fn (AppointmentTypeEnum $state): string => $state->color()),

                BadgeColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (AppointmentStatusEnum $state): string => $state->label())
                    ->color(fn (AppointmentStatusEnum $state): string => $state->color()),

                TextColumn::make('email_alias')
                    ->label('Correo')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('scheduled_at')
                    ->label('Fecha asignada')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sin asignar'),

                TextColumn::make('soldado.name')
                    ->label('Soldado')
                    ->placeholder('—'),

                TextColumn::make('last_review_at')
                    ->label('Última revisión')
                    ->dateTime('d/m/Y H:i', 'America/Mexico_City')
                    ->since()
                    ->placeholder('Sin revisar')
                    ->tooltip(fn ($record) => $record->last_review_at
                        ? 'El bot la revisó por última vez el '
                            .$record->last_review_at->timezone('America/Mexico_City')->format('d/m/Y H:i')
                        : 'El bot todavía no la revisa')
                    ->toggleable(),

                TextColumn::make('office')
                    ->label('Sucursal')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('acknowledgment_path')
                    ->label('Acuse')
                    ->badge()
                    ->state(fn (Appointment $record): string => filled($record->acknowledgment_path) ? 'Descargar' : '—')
                    ->color(fn (Appointment $record): string => filled($record->acknowledgment_path) ? 'success' : 'gray')
                    ->url(fn (Appointment $record): ?string => filled($record->acknowledgment_path)
                        ? route('admin.appointments.acknowledgment.download', ['appointment' => $record])
                        : null)
                    ->openUrlInNewTab(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(AppointmentTypeEnum::options()),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(AppointmentStatusEnum::options()),
            ])
            ->defaultSort('type')
            // Refresca sola cada 5s: al formar en segundo plano, el estado pasa a
            // "Formada" (y luego "Agendada") sin que el usuario tenga que dar F5.
            ->poll('5s')
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Ver detalle')
                        ->icon('heroicon-o-eye')
                        ->modalHeading(fn (Appointment $record): string => 'Cita '.$record->type->label())
                        ->modalWidth('4xl')
                        ->schema([
                            Section::make('La cita')
                                ->columns(3)
                                ->schema([
                                    InfoTextEntry::make('empresa')->label('Empresa')
                                        ->state(fn (Appointment $r): string => $r->registration?->primaryLegalName?->name
                                            ?? $r->registration?->singapur_folder_name
                                            ?? '—')
                                        ->columnSpan(2)->weight('bold'),
                                    InfoTextEntry::make('empresa_rfc')->label('RFC de la empresa')
                                        ->state(fn (Appointment $r): ?string => $r->registration?->rfc)
                                        ->placeholder('Aún sin RFC moral')
                                        ->copyable(),
                                    InfoTextEntry::make('type')->label('Trámite')
                                        ->state(fn (Appointment $r): string => $r->type->label()),
                                    InfoTextEntry::make('status')->label('Estado')->badge()
                                        ->state(fn (Appointment $r): string => $r->status->label())
                                        ->color(fn (Appointment $r): string => $r->status->color()),
                                    InfoTextEntry::make('rejection_reason')->label('Motivo del rechazo')
                                        ->color('danger')
                                        ->columnSpan(3)
                                        ->visible(fn (Appointment $r): bool => filled($r->rejection_reason)),
                                    InfoTextEntry::make('scheduled_at')->label('Fecha asignada por el SAT')
                                        // scheduled_at ya está en hora local de CDMX (acuse del SAT); no reconvertir.
                                        ->dateTime('d/m/Y H:i')
                                        ->placeholder('El SAT aún no asigna fecha'),
                                    InfoTextEntry::make('office')->label('Sucursal')
                                        ->state(fn (Appointment $r): string => self::officeName($r))
                                        ->placeholder('—')->columnSpan(2),
                                    InfoTextEntry::make('preferred_module')->label('Sucursal pedida')
                                        ->state(fn (Appointment $r): ?string => $r->preferred_module
                                            ? (SatModule::where('sat_id', $r->preferred_module)->value('name')
                                                ?? (string) $r->preferred_module)
                                            : null)
                                        ->placeholder('La elige el bot'),
                                    InfoTextEntry::make('email_alias')->label('Correo del pool')
                                        ->placeholder('Sin asignar')
                                        ->helperText('Ahí llega el código que el bot lee en cada revisión.'),
                                    InfoTextEntry::make('formed_at')->label('Formada')
                                        ->dateTime('d/m/Y H:i', self::TIMEZONE)->placeholder('—'),
                                    InfoTextEntry::make('last_review_at')->label('Última revisión del bot')
                                        ->dateTime('d/m/Y H:i', self::TIMEZONE)->placeholder('Sin revisar'),
                                ]),

                            Section::make('Soldado que asiste')
                                ->columns(3)
                                ->schema([
                                    InfoTextEntry::make('soldado.name')->label('Nombre')->placeholder('Sin asignar'),
                                    InfoTextEntry::make('soldado.rfc')->label('RFC')
                                        ->placeholder('⚠️ Sin RFC')
                                        ->helperText('El SAT identifica la cita de inscripción con este RFC.'),
                                    InfoTextEntry::make('soldado.curp')->label('CURP')->placeholder('—'),
                                    InfoTextEntry::make('soldado.email')->label('Correo')->placeholder('—')
                                        ->copyable(),
                                    InfoTextEntry::make('soldado.phone')->label('Teléfono')->placeholder('—'),
                                ]),

                            Section::make('Acuse')
                                ->schema([
                                    InfoTextEntry::make('acknowledgment_path')->hiddenLabel()
                                        ->placeholder('Todavía no hay acuse. Llega cuando el SAT asigna la cita.'),
                                ]),

                            Section::make('Historial')
                                ->description('Cada paso del bot sobre esta cita, del más reciente al más viejo.')
                                ->poll('15s')
                                ->schema([
                                    ViewEntry::make('events')
                                        ->hiddenLabel()
                                        ->view('filament.infolists.event-timeline'),
                                ]),
                        ]),

                    // Relación de socios (.xlsx) que el SAT exige tanto en la cita de
                    // inscripción de persona moral (RFC) como en la de e.firma (FIEL): en
                    // ambas cotejan la tabla de accionistas contra el acta. Previsualiza los
                    // datos, genera el archivo y lo deja listo para descargar y llevar en la USB.
                    Action::make('generateSatRelation')
                        ->label('Relación de socios (.xlsx)')
                        ->icon('heroicon-o-table-cells')
                        ->color('info')
                        ->visible(fn (Appointment $record): bool => in_array(
                            $record->type,
                            [AppointmentTypeEnum::RFC, AppointmentTypeEnum::FIEL],
                            true,
                        )
                            && $record->registration !== null
                            && $record->registration->shareholders()->exists())
                        ->modalHeading('Relación de socios para el SAT')
                        ->modalDescription('Revisa los datos que el SAT cotejará contra el acta. '
                            .'Al confirmar se genera el .xlsx para descargar y llevar en la USB.')
                        ->modalWidth('5xl')
                        ->modalContent(fn (Appointment $record) => view('filament.sat-relation.preview-modal', [
                            'relation' => resolve(SatShareholderRelationService::class)->compile($record->registration),
                        ]))
                        ->modalSubmitActionLabel('Generar y descargar .xlsx')
                        ->action(function (Appointment $record) {
                            try {
                                // Reutiliza el .xlsx de la primera cita si ya existe; solo genera si falta.
                                $document = resolve(SatShareholderRelationService::class)
                                    ->getOrGenerate($record->registration);
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('Error al generar la relación de socios')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return null;
                            }

                            Notification::make()
                                ->title('Relación de socios generada')
                                ->body('Se está descargando el .xlsx. También queda en Documentos, en el detalle de la cita y en el ZIP.')
                                ->success()
                                ->send();

                            // Descarga real: la ruta sirve el archivo con
                            // Content-Disposition: attachment (el navegador lo baja solo).
                            return redirect()->route('admin.documents.relay-download', ['document' => $document]);
                        }),

                    Action::make('sendToBot')
                        ->label('Formar con el bot')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('primary')
                        ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::PENDING_FORMING
                            && $record->soldado_id !== null)
                        ->requiresConfirmation()
                        ->modalDescription('El bot formará la cita en la fila virtual del SAT en segundo plano. '
                            .'Puedes cerrar esto; el resultado llega por correo y verás la cita como "Formada".')
                        ->action(function (Appointment $record): void {
                            // Segundo plano: el modal cierra al instante, el bot trabaja en la cola.
                            FormSatAppointmentJob::dispatch($record->id);

                            Notification::make()
                                ->title('Enviada a formar')
                                ->body('El bot la está formando en segundo plano. Te avisamos por correo cuando quede lista.')
                                ->success()
                                ->send();
                        }),

                    // Revisar status y marcar formada: acciones compartidas con el dashboard
                    // (AppointmentStatusActions) para no duplicar código.
                    AppointmentStatusActions::reviewNow(),

                    AppointmentStatusActions::markFormed(),

                    Action::make('markAttended')
                        ->label('Asistió (completada)')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::SCHEDULED)
                        ->modalHeading('Marcar cita como completada')
                        ->modalDescription('El soldado asistió y el trámite salió bien.')
                        // En la cita de RFC solo se escribe el RFC de persona moral que obtuvo la
                        // empresa (sin foto/CSF: la CSF oficial se sube aparte en Documentos). Con ese
                        // RFC se forma la e.firma en ese momento, sin preguntar (mismo servicio que el soldado).
                        ->form(fn (Appointment $record): array => $record->type === AppointmentTypeEnum::RFC ? [
                            TextInput::make('company_rfc')
                                ->label('RFC de la empresa (persona moral)')
                                ->helperText('El RFC que obtuvo la empresa en esta cita. Con él se forma la cita de e.firma de inmediato.')
                                ->required()
                                ->maxLength(13)
                                ->default($record->registration?->rfc),
                        ] : [])
                        ->action(function (Appointment $record, array $data): void {
                            // Cita de e.firma completada → fin del flujo del SAT.
                            if ($record->type === AppointmentTypeEnum::FIEL) {
                                $record->update(['status' => AppointmentStatusEnum::ATTENDED]);
                                $record->recordEvent(AppointmentEventTypeEnum::ATTENDED, 'El soldado asistió; trámite exitoso.', [], 'user');
                                Notification::make()->title('e.firma completada — flujo del SAT terminado')->success()->send();

                                return;
                            }

                            // Cita de RFC: capturar RFC (mismo servicio compartido) y formar la e.firma
                            // en ese momento, sin preguntar (es el admin).
                            $service = app(RfcCaptureService::class);
                            $service->captureRfc($record, (string) ($data['company_rfc'] ?? ''), 'user');
                            $service->formEfirma($record);

                            Notification::make()->title('RFC guardado — e.firma en formación')
                                ->body('Se guardó el RFC y la cita de e.firma se está formando en el SAT.')->success()->send();
                        }),

                    // Rechazada (con motivo) y No asistió: acciones compartidas con el dashboard.
                    AppointmentStatusActions::markRejected(),

                    AppointmentStatusActions::markNoShow(),

                    Action::make('cancel')
                        ->label('Cancelar cita')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        // Se puede cancelar mientras esté viva (por formar, formada o agendada);
                        // no si ya se completó o ya está en un estado terminal.
                        ->visible(fn (Appointment $record): bool => in_array($record->status, [
                            AppointmentStatusEnum::PENDING_FORMING,
                            AppointmentStatusEnum::FORMED,
                            AppointmentStatusEnum::SCHEDULED,
                        ], true))
                        ->requiresConfirmation()
                        ->modalHeading('Cancelar cita del SAT')
                        ->modalDescription('La cita se marca como cancelada y deja de darse seguimiento. El correo del pool queda en enfriamiento 24h (el SAT lo sigue teniendo registrado).')
                        ->form([
                            Textarea::make('reason')
                                ->label('Motivo (opcional)')
                                ->rows(2),
                        ])
                        ->action(function (Appointment $record, array $data): void {
                            $reason = trim((string) ($data['reason'] ?? ''));

                            // El correo quedó registrado en el SAT y NO cancelamos allá (no hay
                            // endpoint), así que sigue quemado hasta la fecha de la cita. Si la
                            // cita tenía fecha futura, mantenemos el cooldown hasta esa fecha;
                            // si no tenía fecha, 24h desde ahora. claimFor lo respeta por
                            // last_used_at (se libera 24h después de ese sello).
                            if (filled($record->email_alias)) {
                                $burnedUntil = $record->scheduled_at !== null && $record->scheduled_at->isFuture()
                                    ? $record->scheduled_at
                                    : now();

                                AppointmentEmail::query()
                                    ->where('address', $record->email_alias)
                                    ->update(['last_used_at' => $burnedUntil]);
                            }

                            $record->update(['status' => AppointmentStatusEnum::CANCELLED]);
                            $record->recordEvent(
                                AppointmentEventTypeEnum::CANCELLED,
                                'Cita cancelada por el equipo'.($reason !== '' ? ": {$reason}" : '.'),
                                ['reason' => $reason],
                                'user',
                            );

                            // Avisa al equipo (correo + campana), sin romper la cancelación si
                            // el correo falla. Llega a quien active "Cita del SAT cancelada".
                            try {
                                app(EventNotifier::class)->notify(
                                    NotificationEventEnum::SAT_APPOINTMENT_CANCELLED,
                                    new SatAppointmentStatusNotification($record, 'cancelled', $reason ?: null),
                                );
                            } catch (\Throwable $th) {
                                report($th);
                            }

                            // Avisa al soldado para que NO se presente (solo si ya tenía fecha).
                            if ($record->soldado !== null && $record->scheduled_at !== null) {
                                try {
                                    $record->soldado->notify(
                                        new SatAppointmentCancelledNotification($record, $reason ?: null),
                                    );
                                } catch (\Throwable $th) {
                                    report($th);
                                }
                            }

                            Notification::make()->title('Cita cancelada')->success()->send();
                        }),

                    EditAction::make()->label('Editar')->modalHeading('Editar cita')
                        ->after(fn (Appointment $record) => self::autoDispatchForming($record)),
                    DeleteAction::make()->label('Eliminar'),
                ])
                    // Un solo botón "⋮" agrupa todas las acciones para que la tabla no se
                    // rompa ni tenga scroll lateral; despliega las acciones escondidas.
                    ->label('Acciones')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Acciones')
                    ->button()
                    ->hiddenLabel(),
            ])
            ->headerActions([
                // El auto-formado al crear lo hace ahora AppointmentObserver (cubre todos los
                // caminos: expediente, dashboard, móvil, API). Aquí ya no se duplica.
                CreateAction::make()->label('Agregar cita'),
            ]);
    }

    /**
     * Send the appointment to be formed automatically whenever it is saved with a soldado.
     *
     * No toggle: assigning a soldado to a pending_forming appointment always dispatches the
     * forming, so the team never has to remember the manual button. Guarded to a
     * pending_forming appointment that already has a soldado, so the bot has who to queue;
     * FormSatAppointmentJob re-checks the status, so a re-save never re-forms a formed one.
     * If the bot is asleep and the immediate push misses, the bot's own /run cycle also
     * forms pending_forming appointments, so it is never lost.
     *
     * @param  Appointment  $record  The appointment just saved.
     */
    private static function autoDispatchForming(Appointment $record): void
    {
        if ($record->status !== AppointmentStatusEnum::PENDING_FORMING
            || $record->soldado_id === null) {
            return;
        }

        FormSatAppointmentJob::dispatch($record->id);

        Notification::make()
            ->title('Enviada a formar')
            ->body('El bot la está formando en segundo plano. Te avisamos por correo cuando quede lista.')
            ->success()
            ->send();
    }
}
