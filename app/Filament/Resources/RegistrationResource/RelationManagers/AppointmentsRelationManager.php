<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Models\Appointment;
use App\Models\AppointmentEmail;
use App\Models\SatModule;
use App\Services\Sat\SatFormingService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
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
                ->preload(),

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
            ->actions([
                Action::make('sendToBot')
                    ->label('Formar con el bot')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatusEnum::PENDING_FORMING
                        && $record->soldado_id !== null)
                    ->requiresConfirmation()
                    ->modalDescription('El bot formará la cita en la fila virtual del SAT. Tarda ~1 minuto; '
                        .'el resultado llega solo y verás la cita como "Formada".')
                    ->action(function (Appointment $record): void {
                        $ok = app(SatFormingService::class)->dispatchToBot($record);

                        Notification::make()
                            ->title($ok ? 'Enviada al bot' : 'No se pudo enviar')
                            ->body($ok
                                ? 'El bot la está formando. El resultado llega por sí solo en un momento.'
                                : 'Revisa que la cita tenga soldado, que haya un correo libre del pool y que el bot esté configurado.')
                            ->status($ok ? 'success' : 'danger')
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
                    ->action(fn (Appointment $record) => $record->update([
                        'status' => AppointmentStatusEnum::FORMED,
                        'formed_at' => now(),
                    ])),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->headerActions([CreateAction::make()->label('Agregar cita')]);
    }
}
