<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\LegalNameStatusEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Manages proposed company denominations (legal names) for a registration.
 *
 * Enforces business rules: maximum 4 proposals, minimum 3 to allow deletion,
 * and names in PROCESS or APPROVED status cannot be modified.
 */
class LegalNamesRelationManager extends RelationManager
{
    protected static string $relationship = 'legalNames';

    protected static ?string $title = 'Denominaciones sociales';

    /**
     * Define the form schema for creating and editing legal name proposals.
     *
     * @param  Schema  $schema
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Denominación propuesta')->required(),
            TextInput::make('priority')->label('Prioridad (1-4)')->numeric()->required(),
            Select::make('status')
                ->label('Estatus')
                ->options(
                    collect(LegalNameStatusEnum::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                )
                ->required(),
            TextInput::make('clave_unica_denominacion')
                ->label('Clave única (SE)')
                ->nullable(),
            DateTimePicker::make('authorization_timestamp')
                ->label('Fecha de autorización SE')
                ->nullable(),
            DateTimePicker::make('submitted_at')
                ->label('Fecha de envío a dictamen')
                ->nullable(),
        ]);
    }

    /**
     * Define the table columns for the legal names list.
     *
     * @param  Table  $table
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('priority')->label('#')->sortable(),
                TextColumn::make('name')->label('Denominación'),
                BadgeColumn::make('status')
                    ->label('Estatus')
                    ->formatStateUsing(fn (LegalNameStatusEnum $state) => $state->label())
                    ->colors([
                        'gray'    => LegalNameStatusEnum::WAIT->value,
                        'warning' => LegalNameStatusEnum::PENDING->value,
                        'info'    => LegalNameStatusEnum::PROCESS->value,
                        'success' => LegalNameStatusEnum::APPROVED->value,
                        'danger'  => LegalNameStatusEnum::REJECTED->value,
                    ]),
                TextColumn::make('clave_unica_denominacion')->label('Clave SE')->placeholder('—'),
            ])
            ->defaultSort('priority')
            ->actions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()->label('Agregar denominación')]);
    }
}
