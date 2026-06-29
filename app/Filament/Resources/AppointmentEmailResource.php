<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppointmentEmailResource\Pages;
use App\Models\AppointmentEmail;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Catalog of the SAT appointment email pool (super_admin only).
 *
 * Each address delivers to one shared mailbox; the SAT bot is handed an address per
 * appointment and reads the token by IMAP. Here the team adds/lists addresses and sees
 * which are free vs. in use.
 */
class AppointmentEmailResource extends Resource
{
    /**
     * @var class-string<AppointmentEmail>
     */
    protected static ?string $model = AppointmentEmail::class;

    protected static ?string $navigationLabel = 'Pool de correos (SAT)';

    protected static ?string $modelLabel = 'Correo del pool';

    protected static ?string $pluralModelLabel = 'Pool de correos (SAT)';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 12;

    /**
     * Return the icon for this resource in the sidebar.
     */
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-at-symbol';
    }

    /**
     * Restrict access to super_admin only.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Define the create/edit form (used by the table modals).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('address')
                ->label('Correo')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            Toggle::make('is_free')
                ->label('Disponible')
                ->default(true)
                ->helperText('Desactivar para excluirlo del pool sin borrarlo.'),

            TextInput::make('notes')
                ->label('Notas')
                ->maxLength(255),
        ]);
    }

    /**
     * Define the table listing pool addresses.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('address')
                    ->label('Correo')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_free')
                    ->label('Disponible')
                    ->boolean(),

                TextColumn::make('notes')
                    ->label('Notas')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Agregado')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_free')->label('Disponible'),
            ])
            ->headerActions([CreateAction::make()->label('Agregar correo')])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    /**
     * Define the resource pages — list only; create/edit happen in modals.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppointmentEmails::route('/'),
        ];
    }
}
