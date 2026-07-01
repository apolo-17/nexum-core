<?php

namespace App\Filament\Resources;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Filament\Resources\MisCitasResource\Pages;
use App\Models\Appointment;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

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

                IconColumn::make('scheduled')
                    ->label('Agendada')
                    ->boolean()
                    ->state(fn (Appointment $record): bool => $record->isScheduled()),

                TextColumn::make('scheduled_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sin agendar')
                    ->sortable(),

                TextColumn::make('office')
                    ->label('Sede')
                    ->placeholder('—'),

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
            ->defaultSort('scheduled_at', 'desc');
    }

    /**
     * Define the resource pages — list only (read-only).
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMisCitas::route('/'),
        ];
    }
}
