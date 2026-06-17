<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\ShareholderRoleEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Manages the shareholders associated with a registration expedient.
 */
class ShareholdersRelationManager extends RelationManager
{
    protected static string $relationship = 'shareholders';

    protected static ?string $title = 'Socios';

    /**
     * Define the form schema for creating and editing shareholders.
     *
     * @param  Schema  $schema
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre completo')->required(),
            TextInput::make('nationality')->label('Nacionalidad')->required(),
            TextInput::make('passport_number')->label('Pasaporte')->required(),
            TextInput::make('participation_percentage')
                ->label('% de participación')
                ->numeric()
                ->required(),
            Select::make('role')
                ->label('Rol')
                ->options(
                    collect(ShareholderRoleEnum::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                )
                ->required(),
            TextInput::make('email')->label('Correo')->email()->nullable(),
            TextInput::make('phone')->label('Teléfono')->nullable(),
        ]);
    }

    /**
     * Define the table columns for the shareholders list.
     *
     * @param  Table  $table
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre'),
                TextColumn::make('nationality')->label('Nacionalidad'),
                TextColumn::make('passport_number')->label('Pasaporte'),
                TextColumn::make('participation_percentage')->label('%')->suffix('%'),
                TextColumn::make('role')
                    ->label('Rol')
                    ->formatStateUsing(fn (ShareholderRoleEnum $state) => $state->label()),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()->label('Agregar socio')]);
    }
}
