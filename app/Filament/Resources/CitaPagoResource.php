<?php

namespace App\Filament\Resources;

use App\Enums\AppointmentTypeEnum;
use App\Filament\Resources\CitaPagoResource\Pages;
use App\Models\Appointment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Admin-only board to track which SAT citas are paid and which are pending payment.
 *
 * Only shows PAYABLE citas (date passed + result already captured; never rejected/cancelled/
 * no-show), so the list is exactly what could/should be paid. Marking one paid captures the
 * subtotal; IVA (16%) and total are shown and summed automatically.
 */
class CitaPagoResource extends Resource
{
    protected static ?string $model = Appointment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Pagos de citas';

    protected static ?string $modelLabel = 'Pago de cita';

    protected static ?string $pluralModelLabel = 'Pagos de citas';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 5;

    /** Solo el super_admin ve y gestiona los pagos. */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Only payable citas (payment pending or already paid).
     *
     * @return Builder<Appointment>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->payable()
            ->with(['registration.primaryLegalName', 'soldado']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_at', 'desc')
            ->columns([
                TextColumn::make('registration.primaryLegalName.name')
                    ->label('Empresa')
                    ->placeholder('—')
                    ->searchable()
                    ->wrap()
                    // El soldado va como subtítulo de la empresa para ahorrar una columna.
                    ->description(fn (Appointment $r): ?string => $r->soldado?->name),

                TextColumn::make('type')
                    ->label('Trámite')
                    ->badge()
                    ->formatStateUsing(fn (AppointmentTypeEnum $state): string => $state->label())
                    ->color(fn (AppointmentTypeEnum $state): string => $state->color()),

                TextColumn::make('scheduled_at')
                    ->label('Fecha de la cita')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('payment_state')
                    ->label('Pago')
                    ->badge()
                    ->state(fn (Appointment $r): string => $r->paymentState())
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pagada' => 'Pagada',
                        'pendiente' => 'Pendiente de pago',
                        default => 'Aún no',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'pagada' => 'success',
                        'pendiente' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('payment_amount')
                    ->label('Monto (subtotal)')
                    ->money('MXN')
                    ->placeholder('—')
                    ->summarize(
                        Summarizer::make()
                            ->label('Subtotal pagado')
                            ->money('MXN')
                            ->using(fn ($query): float => (float) $query->clone()->whereNotNull('paid_at')->sum('payment_amount')),
                    ),

                TextColumn::make('iva')
                    ->label('IVA (16%)')
                    ->money('MXN')
                    ->state(fn (Appointment $r): ?float => $r->payment_amount !== null ? $r->paymentIva() : null)
                    ->placeholder('—')
                    ->summarize(
                        Summarizer::make()
                            ->label('IVA pagado')
                            ->money('MXN')
                            ->using(fn ($query): float => round((float) $query->clone()->whereNotNull('paid_at')->sum('payment_amount') * Appointment::IVA_RATE, 2)),
                    ),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('MXN')
                    ->weight('bold')
                    ->state(fn (Appointment $r): ?float => $r->payment_amount !== null ? $r->paymentTotal() : null)
                    ->placeholder('—')
                    ->summarize(
                        Summarizer::make()
                            ->label('Total pagado')
                            ->money('MXN')
                            ->using(fn ($query): float => round((float) $query->clone()->whereNotNull('paid_at')->sum('payment_amount') * (1 + Appointment::IVA_RATE), 2)),
                    ),

                TextColumn::make('paid_at')
                    ->label('Fecha de pago')
                    ->dateTime('d/m/Y')
                    ->placeholder('—')
                    ->sortable()
                    // Secundaria: oculta por defecto (se puede mostrar con el toggle de columnas).
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado_pago')
                    ->label('Estado de pago')
                    ->options([
                        'pendiente' => 'Pendientes de pago',
                        'pagada' => 'Pagadas',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'pagada' => $query->whereNotNull('paid_at'),
                        'pendiente' => $query->whereNull('paid_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                Action::make('marcarPagada')
                    ->label('Marcar como pagada')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Appointment $record): bool => $record->paid_at === null)
                    ->modalHeading('Registrar pago de la cita')
                    ->form([
                        TextInput::make('payment_amount')
                            ->label('Monto (subtotal, sin IVA)')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->prefix('$')
                            ->helperText('Se le suma 16% de IVA automáticamente para el total.'),
                    ])
                    ->action(function (Appointment $record, array $data): void {
                        $record->update([
                            'payment_amount' => (float) $data['payment_amount'],
                            'paid_at' => now(),
                            'paid_by' => Auth::id(),
                        ]);
                        Notification::make()->title('Pago registrado')
                            ->body('Total con IVA: $'.number_format($record->fresh()->paymentTotal(), 2).' MXN')
                            ->success()->send();
                    }),

                Action::make('revertirPago')
                    ->label('Revertir pago')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (Appointment $record): bool => $record->paid_at !== null)
                    ->requiresConfirmation()
                    ->modalDescription('La cita vuelve a "Pendiente de pago".')
                    ->action(function (Appointment $record): void {
                        $record->update(['payment_amount' => null, 'paid_at' => null, 'paid_by' => null]);
                        Notification::make()->title('Pago revertido')->warning()->send();
                    }),
                ])
                    ->label('Acciones')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Acciones')
                    ->button()
                    ->hiddenLabel(),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCitaPagos::route('/'),
        ];
    }
}
