<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use Filament\Actions\Action;
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
 * Manages documents for a registration expedient.
 *
 * Documents come from two sources:
 * - Relay KYC files: received via webhook, stored as metadata with relay_zip_path.
 *   Downloaded on-demand via the "Descargar del relay" action.
 * - Manual uploads: added by the notary team and linked to Google Drive.
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
     * Define the table columns and actions for the documents list.
     *
     * @param  Table  $table
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Documento')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn (Document $record): string => $record->name),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (DocumentTypeEnum $state) => $state->label()),
                IconColumn::make('relay_zip_path')
                    ->label('Origen')
                    ->boolean()
                    ->trueIcon('heroicon-o-cloud-arrow-down')
                    ->falseIcon('heroicon-o-user')
                    ->trueColor('info')
                    ->falseColor('gray')
                    ->state(fn (Document $record): bool => filled($record->relay_zip_path))
                    ->tooltip(fn (Document $record): string => filled($record->relay_zip_path)
                        ? 'Documento del relay (descarga disponible)'
                        : 'Documento manual'
                    ),
                IconColumn::make('verified_at')
                    ->label('Verificado')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
                TextColumn::make('uploader.name')->label('Subido por'),
                TextColumn::make('created_at')->label('Fecha')->date('d/m/Y'),
            ])
            ->actions([
                Action::make('downloadFromRelay')
                    ->label('Descargar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->url(
                        fn (Document $record): string => route(
                            'admin.documents.relay-download',
                            $record
                        )
                    )
                    ->openUrlInNewTab()
                    ->visible(fn (Document $record): bool => filled($record->relay_zip_path)),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->headerActions([CreateAction::make()->label('Agregar documento')]);
    }
}
