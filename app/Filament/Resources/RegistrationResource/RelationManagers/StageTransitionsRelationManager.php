<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\RegistrationStageEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only audit log of every stage transition for a registration expedient.
 *
 * Transitions are immutable — they are never created, edited, or deleted from
 * the dashboard. The table renders the full chronological history so the
 * notary team can see who moved each stage and when.
 */
class StageTransitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'stageTransitions';

    protected static ?string $title = 'Historial de etapas';

    /**
     * No form is needed — transitions are immutable records.
     *
     * @param  Schema  $schema
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Define the read-only table for stage transition history.
     *
     * @param  Table  $table
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('from_stage')
                    ->label('Desde')
                    ->formatStateUsing(
                        fn (?RegistrationStageEnum $state) => $state?->label() ?? '—'
                    )
                    ->placeholder('Inicio'),

                TextColumn::make('to_stage')
                    ->label('Hacia')
                    ->formatStateUsing(
                        fn (RegistrationStageEnum $state) => $state->label()
                    )
                    ->weight('bold'),

                TextColumn::make('performer.name')
                    ->label('Realizado por')
                    ->placeholder('Sistema'),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->placeholder('—')
                    ->limit(60)
                    ->wrap(),

                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->paginated(false);
    }

    /**
     * Disable all write actions — transitions are append-only.
     *
     * @return bool
     */
    public function canCreate(): bool
    {
        return false;
    }
}
