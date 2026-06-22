<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\ShareholderRoleEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
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
     * Allow mutations (create, edit, delete) even when rendered inside a ViewRecord page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Define the form schema for creating and editing shareholders.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            // ---- Datos básicos del relay ----------------------------------------
            Section::make('Datos del relay')
                ->description('Información recibida automáticamente desde China vía webhook.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Nombre completo')->required()->columnSpanFull(),
                    TextInput::make('nationality')->label('Nacionalidad')->required(),
                    TextInput::make('passport_number')->label('N.° pasaporte')->nullable(),
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
                    TextInput::make('phone_country_code')->label('Código país tel.')->placeholder('+86')->nullable(),
                    Toggle::make('is_married')
                        ->label('Casado/a')
                        ->helperText('Soltero → 2 docs KYC, Casado → 4 docs (incluye acta matrimonio + pasaporte cónyuge).')
                        ->onColor('success')
                        ->offColor('gray')
                        ->columnSpanFull(),
                ]),

            // ---- Datos para el acta constitutiva --------------------------------
            Section::make('Datos para el acta constitutiva')
                ->description('Se extraen automáticamente al aprobar los documentos KYC vía Claude vision. El equipo notarial puede corregir cualquier valor.')
                ->columns(2)
                ->schema([
                    Select::make('gender')
                        ->label('Género')
                        ->options(['M' => 'Masculino (M)', 'F' => 'Femenino (F)'])
                        ->nullable(),
                    Select::make('civil_status')
                        ->label('Estado civil')
                        ->options([
                            'soltero' => 'Soltero/a',
                            'casado' => 'Casado/a',
                            'divorciado' => 'Divorciado/a',
                            'viudo' => 'Viudo/a',
                        ])
                        ->nullable(),
                    DatePicker::make('birthdate')
                        ->label('Fecha de nacimiento')
                        ->displayFormat('d/m/Y')
                        ->nullable(),
                    TextInput::make('birthplace')
                        ->label('Lugar de nacimiento')
                        ->placeholder('Ciudad, País')
                        ->nullable(),
                    TextInput::make('tax_id')
                        ->label('Tax ID extranjero (NIF / TIN)')
                        ->helperText('Solo si no es nacional chino. Los chinos usan EXTF900101NI1 automáticamente.')
                        ->nullable()
                        ->columnSpanFull(),
                    Textarea::make('address_line')
                        ->label('Dirección de residencia')
                        ->helperText('Se extrae del comprobante de domicilio. Aparecerá en el acta constitutiva.')
                        ->rows(2)
                        ->nullable()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Define the table columns for the shareholders list.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->grow(true),
                TextColumn::make('nationality')->label('Nacionalidad')->grow(false),
                TextColumn::make('passport_number')->label('Pasaporte')->placeholder('—')->grow(false),
                TextColumn::make('participation_percentage')->label('%')->suffix('%')->grow(false),
                TextColumn::make('role')
                    ->label('Rol')
                    ->formatStateUsing(fn (ShareholderRoleEnum $state) => $state->label())
                    ->grow(false),
                IconColumn::make('is_married')
                    ->label('Casado/a')
                    ->boolean()
                    ->trueIcon('heroicon-o-heart')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->grow(false),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()->label('Agregar socio')]);
    }
}
