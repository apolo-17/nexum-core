<?php

namespace App\Filament\Resources\LegalAgentResource\RelationManagers;

use App\Models\Registration;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Shows the actas (registrations) a legal agent is assigned to, with the share
 * percentage held in each. Lets the notary attach/detach actas and edit the
 * percentage directly from the agent's detail page.
 */
class RegistrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrations';

    protected static ?string $title = 'Actas asignadas';

    protected static ?string $recordTitleAttribute = 'singapur_client_code';

    /**
     * Allow mutations even when rendered inside a ViewRecord page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Pivot form used by the attach/edit actions (share percentage).
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('participation_percentage')
                ->label('% de participación')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%'),
        ]);
    }

    /**
     * Define the table of assigned actas.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('singapur_client_code')
                    ->label('Expediente')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('company')
                    ->label('Empresa')
                    ->state(fn (Registration $record): string => $record->primaryLegalName?->name ?? '—'),

                TextColumn::make('stage')
                    ->label('Etapa')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state->label()),

                TextColumn::make('participation_percentage')
                    ->label('% de acciones')
                    ->state(fn (Registration $record): string => $record->pivot->participation_percentage !== null
                        ? number_format((float) $record->pivot->participation_percentage, 2).'%'
                        : '—'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Asignar a un acta')
                    ->recordTitle(fn (Registration $record): string => $record->singapur_client_code
                        .' — '.($record->primaryLegalName?->name ?? 'Sin denominación'))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()->label('Expediente'),
                        TextInput::make('participation_percentage')
                            ->label('% de participación')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                    ]),
            ])
            ->actions([
                EditAction::make()->label('Editar %'),
                DetachAction::make(),
            ]);
    }
}
