<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MuaAccountResource\Pages;
use App\Models\MuaAccount;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Filament resource for managing MUA accounts (soldados FIEL).
 *
 * Only accessible to super_admin. The create/edit form includes FIEL credential
 * fields (certificate, private key, password) that are extracted in the page's
 * lifecycle hooks and persisted to mua_credentials — never to mua_accounts directly.
 */
class MuaAccountResource extends Resource
{
    /**
     * @var class-string<MuaAccount>
     */
    protected static ?string $model = MuaAccount::class;

    /**
     * @var string|null
     */
    protected static ?string $navigationLabel = 'Cuentas MUA (FIEL)';

    /**
     * Return the icon for this resource in the sidebar.
     *
     * Overrides the property to avoid PHP type-incompatibility with BackedEnum.
     *
     * @return string
     */
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-key';
    }

    /**
     * Navigation group — must match parent type exactly: string | UnitEnum | null.
     *
     * @var string|\UnitEnum|null
     */
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    /**
     * @var int|null
     */
    protected static ?int $navigationSort = 10;

    /**
     * Restrict access to super_admin only.
     *
     * @return bool
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Define the form for creating / editing a MuaAccount.
     *
     * Credential fields (certificate_b64, private_key_b64, private_key_password) are
     * virtual — they do not map to columns on mua_accounts. The page's lifecycle hooks
     * (mutateFormDataBeforeCreate / mutateFormDataBeforeSave) strip them out before
     * Eloquent touches the model and persist them to mua_credentials instead.
     *
     * @param  Schema  $schema
     *
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del soldado')
                ->description('Información de la persona cuya FIEL se usará para el trámite MUA.')
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre completo')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Correo electrónico')
                        ->email()
                        ->required()
                        ->maxLength(255),

                    TextInput::make('rfc')
                        ->label('RFC')
                        ->required()
                        ->length(13)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null ? strtoupper($state) : null)
                        ->unique(ignoreRecord: true),

                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->helperText('Desactivar para excluir esta cuenta del bot sin eliminarla.'),
                ])->columns(2),

            Section::make('Credenciales FIEL (e.firma)')
                ->description('Archivos de la e.firma en formato base64. Al editar, dejar en blanco conserva las credenciales actuales sin cambios.')
                ->schema([
                    TextInput::make('certificate_b64')
                        ->label('Certificado .cer (base64)')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->password()
                        ->revealable()
                        ->helperText('Pega el contenido base64 del archivo .cer de la FIEL.'),

                    TextInput::make('private_key_b64')
                        ->label('Llave privada .key (base64)')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->password()
                        ->revealable()
                        ->helperText('Pega el contenido base64 del archivo .key de la FIEL.'),

                    TextInput::make('private_key_password')
                        ->label('Contraseña de la llave privada')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->password()
                        ->revealable()
                        ->helperText('La contraseña que protege el archivo .key.'),
                ])->columns(1),
        ]);
    }

    /**
     * Define the table listing MuaAccounts.
     *
     * @param  Table  $table
     *
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Soldado')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),

                TextColumn::make('rfc')
                    ->label('RFC')
                    ->searchable(),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),

                TextColumn::make('active_submissions')
                    ->label('En proceso')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('credentials_count')
                    ->label('Credenciales')
                    ->counts('credentials')
                    ->badge()
                    ->color(fn (int $state): string => $state === 3 ? 'success' : 'warning'),

                TextColumn::make('created_at')
                    ->label('Registrado')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Define the resource pages.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMuaAccounts::route('/'),
            'create' => Pages\CreateMuaAccount::route('/create'),
            'edit'   => Pages\EditMuaAccount::route('/{record}/edit'),
        ];
    }
}
