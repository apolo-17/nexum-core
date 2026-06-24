<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\LegalAgentTypeEnum;
use App\Models\LegalAgent;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Assigns legal representatives and commissaries (from the catalog) to this acta.
 *
 * The notary picks an active agent from the catalog and sets the share percentage
 * it holds in this acta. The percentage is stored on the pivot, so the same agent
 * can hold different percentages across different actas.
 */
class LegalAgentsRelationManager extends RelationManager
{
    protected static string $relationship = 'legalAgents';

    protected static ?string $title = 'Representantes y comisarios';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Allow mutations even when rendered inside a ViewRecord page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Pivot form used by the edit action (share percentage).
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
     * Define the table of agents assigned to this acta.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Rol')
                    ->badge()
                    ->formatStateUsing(fn (LegalAgentTypeEnum $state): string => $state->label())
                    ->color(fn (LegalAgentTypeEnum $state): string => $state->color()),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),

                TextColumn::make('rfc')
                    ->label('RFC')
                    ->toggleable(),

                TextColumn::make('participation_percentage')
                    ->label('% de acciones')
                    ->state(fn (LegalAgent $record): string => $record->pivot->participation_percentage !== null
                        ? number_format((float) $record->pivot->participation_percentage, 2).'%'
                        : '—'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Rol')
                    ->options(LegalAgentTypeEnum::options()),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Asignar del catálogo')
                    ->recordTitle(fn (LegalAgent $record): string => $record->type->label().' — '.$record->name)
                    ->recordSelectSearchColumns(['name', 'rfc'])
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('Representante / comisario')
                            // Only active catalog entries can be attached.
                            ->modifyOptionsQueryUsing(fn ($query) => $query->where('is_active', true)),
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
