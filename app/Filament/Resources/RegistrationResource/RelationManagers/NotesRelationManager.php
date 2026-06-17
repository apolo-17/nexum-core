<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Manages internal notes written by the notary team for a registration expedient.
 *
 * Notes appear ordered by pinned status first, then by date descending.
 */
class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'Notas internas';

    /**
     * Define the form schema for creating and editing notes.
     *
     * @param  Schema  $schema
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('content')
                ->label('Nota')
                ->required()
                ->rows(4),
            Checkbox::make('is_pinned')
                ->label('Fijar nota (aparece primero)'),
        ]);
    }

    /**
     * Define the table columns for the notes feed.
     *
     * @param  Table  $table
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('is_pinned')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-o-star')
                    ->falseIcon('')
                    ->trueColor('warning'),
                TextColumn::make('content')
                    ->label('Nota')
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('author.name')->label('Escrita por'),
                TextColumn::make('created_at')->label('Fecha')->since(),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->headerActions([
                CreateAction::make()
                    ->label('Nueva nota')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();

                        return $data;
                    }),
            ]);
    }
}
