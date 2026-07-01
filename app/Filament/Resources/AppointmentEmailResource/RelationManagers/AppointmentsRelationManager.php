<?php

namespace App\Filament\Resources\AppointmentEmailResource\RelationManagers;

use App\Enums\AppointmentTypeEnum;
use App\Enums\EfirmaAppointmentStatusEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only history of the SAT appointments that have used this pool address:
 * which companies, appointment type and status.
 */
class AppointmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'appointments';

    protected static ?string $title = 'Citas que han usado este correo';

    /**
     * Define the read-only table of appointments for this pool address.
     */
    public function table(Table $table): Table
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
                    ->formatStateUsing(fn (EfirmaAppointmentStatusEnum $state): string => $state->label())
                    ->color(fn (EfirmaAppointmentStatusEnum $state): string => $state->color()),

                TextColumn::make('soldado.name')
                    ->label('Soldado')
                    ->placeholder('—'),

                TextColumn::make('scheduled_at')
                    ->label('Fecha de cita')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sin agendar'),

                TextColumn::make('created_at')
                    ->label('Asignado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
