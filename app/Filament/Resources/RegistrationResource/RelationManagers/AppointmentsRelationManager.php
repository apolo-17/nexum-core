<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\AppointmentTypeEnum;
use App\Enums\EfirmaAppointmentStatusEnum;
use App\Models\Appointment;
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
 * Every company needs two appointments: one to obtain the RFC and one to issue the
 * FIEL (e.firma). Captured manually here; the SAT bot will later fill the date, office
 * and status via a callback. A rejected / no-showed appointment is kept and a new one
 * is created for the reschedule.
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
                ->options(self::statusOptions())
                ->default(EfirmaAppointmentStatusEnum::PENDING_SCHEDULING->value)
                ->required(),

            Select::make('soldado_id')
                ->label('Soldado que asiste')
                ->relationship('soldado', 'name')
                ->searchable()
                ->preload(),

            DateTimePicker::make('scheduled_at')
                ->label('Fecha y hora de la cita')
                ->native(false),

            TextInput::make('office')
                ->label('Sede / oficina del SAT')
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
                    ->formatStateUsing(fn (EfirmaAppointmentStatusEnum $state): string => $state->label())
                    ->color(fn (EfirmaAppointmentStatusEnum $state): string => $state->color()),

                TextColumn::make('scheduled_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sin agendar'),

                TextColumn::make('soldado.name')
                    ->label('Soldado')
                    ->placeholder('—'),

                TextColumn::make('office')
                    ->label('Sede')
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
            ])
            ->defaultSort('type')
            ->actions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()->label('Agregar cita')]);
    }

    /**
     * Build the status value => label map for the select input.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(EfirmaAppointmentStatusEnum::cases())
            ->mapWithKeys(fn (EfirmaAppointmentStatusEnum $case): array => [$case->value => $case->label()])
            ->all();
    }
}
