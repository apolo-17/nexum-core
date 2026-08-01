<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard board of denominations still in flight, for the super_admin.
 *
 * Shows the pool denominations that are not yet resolved (draft, queued, being sent, or
 * under review at the SE) so the team can see at a glance what is pending. Approved and
 * rejected ones drop off — this is a "what still needs attention" list.
 */
class DenominationsBoard extends TableWidget
{
    protected static ?int $sort = -7;

    protected int|string|array $columnSpan = 'full';

    /**
     * Only the super_admin sees this board.
     */
    public static function canView(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Build the pending-denominations table.
     */
    public function table(Table $table): Table
    {
        return $table
            ->heading('Denominaciones pendientes')
            ->description('Denominaciones que siguen en proceso (borrador, en cola o en la SE).')
            ->query(
                LegalName::query()
                    ->with(['soldado', 'registration.primaryLegalName'])
                    ->whereIn('status', [
                        LegalNameStatusEnum::DRAFT->value,
                        LegalNameStatusEnum::WAIT->value,
                        LegalNameStatusEnum::SUBMITTING->value,
                        LegalNameStatusEnum::PENDING->value,
                        LegalNameStatusEnum::PROCESS->value,
                    ])
            )
            ->poll('10s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Denominación')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('company_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => strtoupper((string) $state)),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (LegalNameStatusEnum $state): string => $state->label())
                    ->color(fn (LegalNameStatusEnum $state): string => $state->color()),

                TextColumn::make('soldado.name')
                    ->label('FIEL')
                    ->placeholder('Se asigna al enviar'),

                TextColumn::make('clave_unica_denominacion')
                    ->label('Folio SE')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Generada')
                    ->dateTime('d/m/Y H:i', 'America/Mexico_City')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(fn (): array => collect(LegalNameStatusEnum::cases())
                        ->mapWithKeys(fn (LegalNameStatusEnum $case): array => [$case->value => $case->label()])
                        ->all()),
            ]);
    }
}
