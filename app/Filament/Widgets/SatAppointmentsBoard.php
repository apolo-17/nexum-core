<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Filament\Support\AppointmentDetail;
use App\Filament\Support\AppointmentStatusActions;
use App\Models\Appointment;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard board of SAT appointments for the super_admin.
 *
 * One row per appointment: company, soldado (with email + status), appointment status,
 * date, and whether its paperwork is complete. Clicking a row opens the full detail —
 * timeline (requested → formed → scheduled) and the downloadable acuse and tax-address
 * proof — reusing AppointmentDetail so it matches the rest of the app.
 */
class SatAppointmentsBoard extends TableWidget
{
    protected static ?int $sort = -8;

    protected int|string|array $columnSpan = 'full';

    /**
     * Only the super_admin sees this board.
     */
    public static function canView(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Build the appointments table.
     */
    public function table(Table $table): Table
    {
        return $table
            ->heading('Citas del SAT')
            ->description('Estado de cada empresa en el SAT. Haz clic en una fila para ver el detalle.')
            ->query(
                Appointment::query()
                    ->with(['registration.primaryLegalName', 'soldado.user'])
            )
            ->poll('10s')
            ->defaultSort('scheduled_at', 'desc')
            ->columns([
                TextColumn::make('registration.primaryLegalName.name')
                    ->label('Empresa')
                    ->placeholder('—')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('type')
                    ->label('Trámite')
                    ->badge()
                    ->formatStateUsing(fn (AppointmentTypeEnum $state): string => $state->label())
                    ->color(fn (AppointmentTypeEnum $state): string => $state->color()),

                TextColumn::make('soldado.name')
                    ->label('Soldado')
                    ->placeholder('Sin asignar')
                    ->description(fn (Appointment $record): ?string => $record->email_alias),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (AppointmentStatusEnum $state): string => $state->label())
                    ->color(fn (AppointmentStatusEnum $state): string => $state->color()),

                TextColumn::make('scheduled_at')
                    ->label('Fecha de la cita')
                    // scheduled_at ya viene en hora local de CDMX (wall-clock del acuse del
                    // SAT); NO reconvertir zona o se muestra 6h antes.
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sin fecha')
                    ->sortable(),

                TextColumn::make('documentation')
                    ->label('Documentación')
                    ->badge()
                    ->state(fn (Appointment $record): string => AppointmentDetail::documentation($record)['label'])
                    ->color(fn (Appointment $record): string => AppointmentDetail::documentation($record)['color']),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(AppointmentStatusEnum::options()),
                SelectFilter::make('type')
                    ->label('Trámite')
                    ->options(AppointmentTypeEnum::options()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ver detalle')
                    ->modalHeading(fn (Appointment $record): string => 'Cita '.$record->type->label())
                    ->modalWidth('4xl')
                    ->schema(AppointmentDetail::sections()),
                // Actualizar el estado de la cita SIN entrar al expediente (mismo control que allá).
                AppointmentStatusActions::group(),
            ])
            ->recordAction('view');
    }
}
