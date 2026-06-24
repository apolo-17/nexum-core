<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MuaAccountResource\Pages;
use App\Models\MuaAccount;
use App\Models\MuaCredential;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
 * Only accessible to super_admin. Allows uploading FIEL credentials
 * (certificate, private key, password) for each account so the bot
 * can authenticate with the SE's MUA portal to submit denominations.
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

                Action::make('upload_certificate')
                    ->label('Subir certificado (.cer)')
                    ->icon('heroicon-o-document-arrow-up')
                    ->color('info')
                    ->form([
                        TextInput::make('certificate_b64')
                            ->label('Contenido .cer (base64)')
                            ->required()
                            ->helperText('Pega el contenido base64 del archivo .cer de la FIEL.')
                            ->password()
                            ->revealable(),
                    ])
                    ->action(function (MuaAccount $record, array $data): void {
                        MuaCredential::updateOrCreate(
                            ['mua_account_id' => $record->id, 'type' => 'certificate'],
                            []
                        )->setEncryptedValue($data['certificate_b64'])->save();

                        Notification::make()
                            ->title('Certificado guardado correctamente.')
                            ->success()
                            ->send();
                    }),

                Action::make('upload_private_key')
                    ->label('Subir llave privada (.key)')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->form([
                        TextInput::make('private_key_b64')
                            ->label('Contenido .key (base64)')
                            ->required()
                            ->helperText('Pega el contenido base64 del archivo .key de la FIEL.')
                            ->password()
                            ->revealable(),

                        TextInput::make('password')
                            ->label('Contraseña de la llave privada')
                            ->required()
                            ->password()
                            ->revealable(),
                    ])
                    ->action(function (MuaAccount $record, array $data): void {
                        MuaCredential::updateOrCreate(
                            ['mua_account_id' => $record->id, 'type' => 'private_key'],
                            []
                        )->setEncryptedValue($data['private_key_b64'])->save();

                        MuaCredential::updateOrCreate(
                            ['mua_account_id' => $record->id, 'type' => 'password'],
                            []
                        )->setEncryptedValue($data['password'])->save();

                        Notification::make()
                            ->title('Llave privada y contraseña guardadas correctamente.')
                            ->success()
                            ->send();
                    }),

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
