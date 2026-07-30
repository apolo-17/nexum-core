<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Models\Appointment;
use App\Models\AppointmentEmail;
use App\Models\SatModule;
use App\Jobs\FormSatAppointmentJob;
use App\Services\Sat\SatReviewService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\TextEntry as InfoTextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
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
                ->relationship('soldado', 'name')
                ->searchable()
                ->preload()
                ->live(),

            Toggle::make('form_now')
                ->label('Formar en la fila virtual al guardar')
                ->helperText('El bot lo formará en segundo plano en cuanto guardes; te avisamos por correo. '
                    .'Si lo apagas, después lo formas con el botón "Formar con el bot".')
                ->default(true)
                ->inline(false)
                // Solo tiene sentido si hay soldado y la cita está por formar.
                ->visible(fn (Get $get): bool => filled($get('soldado_id'))
                    && ($get('status') ?? AppointmentStatusEnum::PENDING_FORMING->value)
                        === AppointmentStatusEnum::PENDING_FORMING->value),

            Select::make('preferred_module')
                ->label('Sucursal del SAT donde formar')
                ->options(fn (): array => SatModule::options())
                ->searchable()
                ->helperText('Opcional. Si la dejas vacía, el bot elige entre las sucursales de CDMX.'),

            Select::make('email_alias')
                ->label('Correo del pool usado para formar')
                ->options(fn (): array => AppointmentEmail::orderBy('address')->pluck('address', 'address')->all())
                ->searchable()
                ->helperText('Lo asigna el bot al formar. Solo llénalo si formaste la cita a mano.'),

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

                EditAction::make()->label('Editar')->modalHeading('Editar cita')
                    ->after(fn (Appointment $record, array $data) => self::maybeDispatchForming($record, $data)),
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
                    ->after(fn (Appointment $record, array $data) => self::maybeDispatchForming($record, $data)),
            ]);
    }

    /**
     * Send the appointment to be formed if the user asked for it when saving.
     *
     * The "Formar al guardar" toggle (form_now) is not a DB column — it only signals
     * intent here. Fires only for a pending_forming appointment that already has a
     * soldado, so the bot has who to queue.
     *
     * @param  Appointment  $record  The appointment just saved.
     * @param  array<string, mixed>  $data  The submitted form data (carries form_now).
     */
    private static function maybeDispatchForming(Appointment $record, array $data): void
    {
        $wantsForming = (bool) ($data['form_now'] ?? false);

        if (! $wantsForming
            || $record->status !== AppointmentStatusEnum::PENDING_FORMING
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
