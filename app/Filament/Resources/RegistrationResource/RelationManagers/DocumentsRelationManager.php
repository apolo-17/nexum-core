<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\DocumentTypeEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Manages documents stored in Google Drive for a registration expedient.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documentos';

    /**
     * Define the form schema for uploading and editing document references.
     *
     * @param  Schema  $schema
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Tipo de documento')
                ->options(
                    collect(DocumentTypeEnum::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                )
                ->required(),
            TextInput::make('name')->label('Nombre descriptivo')->required(),
            TextInput::make('google_drive_file_id')->label('ID en Google Drive')->required(),
            TextInput::make('google_drive_url')->label('URL de Drive')->url()->required(),
        ]);
    }

    /**
     * Define the table columns for the documents list.
     *
     * @param  Table  $table
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Documento')->searchable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (DocumentTypeEnum $state) => $state->label()),
                IconColumn::make('verified_at')
                    ->label('Verificado')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
                TextColumn::make('uploader.name')->label('Subido por'),
                TextColumn::make('created_at')->label('Fecha')->date('d/m/Y'),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()->label('Agregar documento')]);
    }
}
