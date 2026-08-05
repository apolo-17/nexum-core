<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Jobs\FormSatAppointmentJob;
use App\Models\Appointment;
use App\Models\SatModule;
use App\Services\Sat\SatReviewService;
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
                ->helperText('Cada empresa necesita una cita RFC y una cita e.firma (FIEL).'),

            Select::make('status')
                ->label('Estado')
                ->options(AppointmentStatusEnum::options())
                ->default(AppointmentStatusEnum::PENDING_FORMING->value)
                ->required(),

            Select::make('soldado_id')
                ->label('Soldado que asiste')
                // Solo los apoderados del acta de ESTA empresa que además tengan lo que
                // la cita necesita: RFC (identifica al soldado ante el SAT) y correo
                // (para notificarle). La CURP no la pide el SAT para la cita.
                ->options(fn ($livewire): array => $livewire->getOwnerRecord()
                    ->legalRepresentatives()
                    ->whereNotNull('rfc')
                    ->whereNotNull('email')
                    ->orderBy('name')
                    ->pluck('name', 'soldados.id')
                    ->all())
                ->helperText('Apoderados del acta de esta empresa con RFC y correo (el correo se usa para avisarle al soldado).')
                ->searchable()
                ->live(),

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
                                    InfoTextEntry::make('type')->label('Trámite')
                                        ->state(fn (Appointment $r): string => $r->type->label()),
                                    InfoTextEntry::make('status')->label('Estado')->badge()
                                        ->state(fn (Appointment $r): string => $r->status->label())
                                        ->color(fn (Appointment $r): string => $r->status->color()),
                                    InfoTextEntry::make('scheduled_at')->label('Fecha asignada por el SAT')
                                        ->dateTime('d/m/Y H:i', self::TIMEZONE)
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
                                        ->view('filament.infolists.appointment-timeline'),
                                ]),
                        ]),

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

                    Action::make('reviewNow')
                        ->label('Revisar status ahora')
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::FORMED)
                        ->modalHeading('Revisar el status en el SAT')
                        ->modalDescription('Voy a consultar el SAT en vivo para ver si ya te asignaron fecha. '
                            .'Tarda unos segundos.')
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
                        }),

                    Action::make('markFormed')
                        ->label('Marcar formada (a mano)')
                        ->icon('heroicon-o-check-circle')
                        ->color('warning')
                        ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::PENDING_FORMING)
                        ->requiresConfirmation()
                        ->modalDescription('Úsalo solo si TÚ formaste la cita en el portal del SAT. Captura también '
                            .'el correo del pool que usaste, o el bot no podrá leer el código.')
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
                        }),

                    Action::make('markAttended')
                        ->label('Asistió (completada)')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::SCHEDULED)
                        ->modalHeading('Marcar cita como completada')
                        ->modalDescription('El soldado asistió y el trámite salió bien.')
                        // En la cita de RFC se captura el RFC de persona moral que obtuvo la
                        // empresa: con él se forma la cita de e.firma (el solicitante ya es la
                        // empresa, no el soldado). La CSF es opcional.
                        ->form(fn (Appointment $record): array => $record->type === AppointmentTypeEnum::RFC ? [
                            TextInput::make('company_rfc')
                                ->label('RFC de la empresa (persona moral)')
                                ->helperText('El RFC que obtuvo la empresa en esta cita. Con él se formará la cita de e.firma.')
                                ->required()
                                ->maxLength(13)
                                ->default($record->registration?->rfc),
                            FileUpload::make('rfc_evidence')
                                ->label('Evidencia del RFC (CSF y/o fotos) — opcional')
                                ->helperText('Sube la Constancia de Situación Fiscal y/o fotos del RFC. Quedan en Documentos del expediente.')
                                ->multiple()
                                ->disk(config('filesystems.default'))
                                ->directory('documents/rfc-evidence')
                                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/webp']),
                        ] : [])
                        ->action(function (Appointment $record, array $data): void {
                            // Cita de RFC: guardar el RFC de la empresa (+ evidencia si se subió).
                            if ($record->type === AppointmentTypeEnum::RFC) {
                                if (filled($data['company_rfc'] ?? null)) {
                                    $record->registration?->update(['rfc' => strtoupper(trim((string) $data['company_rfc']))]);
                                }

                                foreach ((array) ($data['rfc_evidence'] ?? []) as $path) {
                                    if (blank($path)) {
                                        continue;
                                    }

                                    $isPdf = str_ends_with(strtolower((string) $path), '.pdf');

                                    \App\Models\Document::create([
                                        'registration_id' => $record->registration_id,
                                        // La CSF (PDF) como CSF; las fotos como documento del RFC.
                                        'type' => $isPdf
                                            ? \App\Enums\DocumentTypeEnum::CSF
                                            : \App\Enums\DocumentTypeEnum::RFC_DOCUMENT,
                                        'name' => basename((string) $path),
                                        'storage_path' => $path,
                                        'stage' => $record->registration?->getRawOriginal('stage'),
                                        'verified_at' => now(),
                                    ]);
                                }
                            }

                            $record->update(['status' => AppointmentStatusEnum::ATTENDED]);
                            $record->recordEvent(AppointmentEventTypeEnum::ATTENDED, 'El soldado asistió; trámite exitoso.', [], 'user');

                            // Cita de e.firma completada → fin del flujo del SAT.
                            if ($record->type === AppointmentTypeEnum::FIEL) {
                                Notification::make()->title('e.firma completada — flujo del SAT terminado')->success()->send();

                                return;
                            }

                            // Cita de RFC completada → crea la de e.firma (se formará con el RFC).
                            $hasFiel = $record->registration?->appointments()
                                ->where('type', AppointmentTypeEnum::FIEL->value)->exists();

                            if (! $hasFiel) {
                                $record->registration?->appointments()->create([
                                    'type' => AppointmentTypeEnum::FIEL->value,
                                    'status' => AppointmentStatusEnum::PENDING_FORMING->value,
                                    'soldado_id' => $record->soldado_id,
                                ]);
                                Notification::make()->title('RFC guardado — cita de e.firma creada')
                                    ->body('La cita de e.firma (por formar) se formará con el RFC de la empresa. Fórmala cuando quieras.')->success()->send();
                            } else {
                                Notification::make()->title('RFC guardado')->success()->send();
                            }
                        }),

                    Action::make('markRejected')
                        ->label('Rechazada por el SAT')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::SCHEDULED)
                        ->requiresConfirmation()
                        ->modalDescription('El SAT rechazó el trámite. Luego saca una nueva cita de RFC con "Agregar cita".')
                        ->action(function (Appointment $record): void {
                            $record->update(['status' => AppointmentStatusEnum::REJECTED]);
                            $record->recordEvent(AppointmentEventTypeEnum::REJECTED, 'El SAT rechazó el trámite en la cita.', [], 'user');
                            Notification::make()->title('Marcada como rechazada')
                                ->body('Saca una nueva cita de RFC con "Agregar cita".')->warning()->send();
                        }),

                    Action::make('markNoShow')
                        ->label('No asistió')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->color('danger')
                        ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::SCHEDULED)
                        ->requiresConfirmation()
                        ->action(function (Appointment $record): void {
                            $record->update(['status' => AppointmentStatusEnum::NO_SHOW]);
                            $record->recordEvent(AppointmentEventTypeEnum::NO_SHOW, 'El soldado no asistió a la cita.', [], 'user');
                            Notification::make()->title('Marcada como no asistió')->warning()->send();
                        }),

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

                            // El correo quedó registrado en el SAT — no lo reciclamos de
                            // inmediato: cooldown 24h (claimFor lo respeta por last_used_at).
                            if (filled($record->email_alias)) {
                                \App\Models\AppointmentEmail::query()
                                    ->where('address', $record->email_alias)
                                    ->update(['last_used_at' => now()]);
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
                                app(\App\Services\Notifications\EventNotifier::class)->notify(
                                    \App\Enums\NotificationEventEnum::SAT_APPOINTMENT_CANCELLED,
                                    new \App\Notifications\SatAppointmentStatusNotification($record, 'cancelled', $reason ?: null),
                                );
                            } catch (\Throwable $th) {
                                report($th);
                            }

                            // Avisa al soldado para que NO se presente (solo si ya tenía fecha).
                            if ($record->soldado !== null && $record->scheduled_at !== null) {
                                try {
                                    $record->soldado->notify(
                                        new \App\Notifications\SatAppointmentCancelledNotification($record, $reason ?: null),
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
                CreateAction::make()->label('Agregar cita')
                    ->after(fn (Appointment $record) => self::autoDispatchForming($record)),
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
