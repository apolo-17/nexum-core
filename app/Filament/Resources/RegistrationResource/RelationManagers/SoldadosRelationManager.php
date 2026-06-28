<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\LegalAgentTypeEnum;
use App\Models\Soldado;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Assigns soldados (as legal representatives or commissaries) to this acta.
 *
 * The notary picks an eligible soldado from the catalog, chooses the role it plays
 * in this acta, and sets the share percentage. Both the role and the percentage live
 * on the pivot, so the same soldado can act in different roles / percentages across
 * different actas.
 */
class SoldadosRelationManager extends RelationManager
{
    protected static string $relationship = 'soldados';

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
     * Pivot form used by the edit action (role + share percentage).
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('role')
                ->label('Rol')
                ->options(LegalAgentTypeEnum::options())
                ->required(),

            TextInput::make('participation_percentage')
                ->label('% de participación')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%'),
        ]);
    }

    /**
     * Define the table of soldados assigned to this acta.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('role')
                    ->label('Rol')
                    ->badge()
                    ->state(fn (Soldado $record): ?LegalAgentTypeEnum => $record->pivot->role !== null
                        ? LegalAgentTypeEnum::from($record->pivot->role)
                        : null)
                    ->formatStateUsing(fn (?LegalAgentTypeEnum $state): string => $state?->label() ?? '—')
                    ->color(fn (?LegalAgentTypeEnum $state): string => $state?->color() ?? 'gray'),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),

                TextColumn::make('rfc')
                    ->label('RFC')
                    ->toggleable(),

                TextColumn::make('participation_percentage')
                    ->label('% de acciones')
                    ->state(fn (Soldado $record): string => $record->pivot->participation_percentage !== null
                        ? number_format((float) $record->pivot->participation_percentage, 2).'%'
                        : '—'),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Rol')
                    ->options(LegalAgentTypeEnum::options())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->wherePivot('role', $data['value'])
                        : $query),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Asignar del catálogo')
                    ->recordTitle(fn (Soldado $record): string => $record->name.' — '.$record->rfc)
                    ->recordSelectSearchColumns(['name', 'rfc'])
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('Soldado')
                            // Only soldados flagged as rep/commissary and active can be attached.
                            ->modifyOptionsQueryUsing(fn (Builder $query): Builder => $query
                                ->where('is_active', true)
                                ->where(fn (Builder $q): Builder => $q
                                    ->where('available_as_legal_representative', true)
                                    ->orWhere('available_as_commissary', true))),
                        Select::make('role')
                            ->label('Rol')
                            ->options(LegalAgentTypeEnum::options())
                            ->required(),
                        TextInput::make('participation_percentage')
                            ->label('% de participación')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                    ]),
            ])
            ->actions([
                EditAction::make()->label('Editar'),
                DetachAction::make(),
            ]);
    }
}
