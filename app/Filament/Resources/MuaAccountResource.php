<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MuaAccountResource\Pages;
use App\Models\MuaAccount;
use App\Models\MuaCredential;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry as InfoTextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
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

    protected static ?string $navigationLabel = 'Cuentas MUA (FIEL)';

    /**
     * Return the icon for this resource in the sidebar.
     *
     * Overrides the property to avoid PHP type-incompatibility with BackedEnum.
     */
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-key';
    }

    /**
     * Navigation group — must match parent type exactly: string | UnitEnum | null.
     */
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 10;

    /**
     * Restrict access to super_admin only.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Hide from navigation — deprecated in favor of SoldadoResource.
     *
     * The MUA pipeline now reads soldados; this resource and the mua_accounts table
     * are kept one release for safety. Soldados manage their FIEL from SoldadoResource.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Define the form for creating / editing a MuaAccount.
     *
     * Credential fields (certificate_file, private_key_file, private_key_password) are
     * virtual — they do not map to columns on mua_accounts. The user uploads the raw
     * .cer / .key files; the page's lifecycle hooks base64-encode them, strip them out
     * before Eloquent touches the model, and persist them to mua_credentials instead.
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
                ->description('Sube los archivos de la e.firma tal cual (.cer y .key). Nosotros los procesamos y guardamos cifrados. Al editar, deja los archivos vacíos para conservar las credenciales actuales sin cambios.')
                ->schema([
                    FileUpload::make('certificate_file')
                        ->label('Certificado (.cer)')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->storeFiles(false)
                        ->maxSize(2048)
                        ->rules([fn (): Closure => self::extensionRule('cer')])
                        ->helperText('Sube el archivo .cer de la FIEL.'),

                    FileUpload::make('private_key_file')
                        ->label('Llave privada (.key)')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->storeFiles(false)
                        ->maxSize(2048)
                        ->rules([fn (): Closure => self::extensionRule('key')])
                        ->helperText('Sube el archivo .key de la FIEL.'),

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
     * Read an uploaded FIEL file from the form state and return its base64 content.
     *
     * FileUpload fields use storeFiles(false), so the form state carries the raw
     * uploaded file (a Livewire TemporaryUploadedFile) instead of a stored path.
     * The .cer / .key bytes are base64-encoded here — exactly what the MUA bot
     * expects — so the user only ever uploads the file as-is, never base64 text.
     *
     * @param  mixed  $value  The FileUpload field state (file, array of files, or null).
     * @return string|null Base64-encoded file content, or null when no file was provided.
     */
    public static function uploadedFileToBase64(mixed $value): ?string
    {
        $file = is_array($value) ? reset($value) : $value;

        if (! $file instanceof UploadedFile) {
            return null;
        }

        $content = method_exists($file, 'get')
            ? $file->get()
            : @file_get_contents($file->getRealPath());

        return $content === false || $content === null ? null : base64_encode($content);
    }

    /**
     * Determine whether an uploaded file has the given extension (case-insensitive).
     *
     * MIME types for .cer / .key are unreliable (often application/octet-stream),
     * so we enforce by the original file extension instead.
     *
     * @param  mixed  $value  The FileUpload field state (file, array of files, or null).
     * @param  string  $extension  Expected extension without the dot (e.g. 'cer').
     * @return bool True when a file is present and its extension matches.
     */
    public static function uploadedHasExtension(mixed $value, string $extension): bool
    {
        $file = is_array($value) ? reset($value) : $value;

        return $file instanceof UploadedFile
            && strtolower($file->getClientOriginalExtension()) === strtolower($extension);
    }

    /**
     * Build a validation rule that rejects uploads whose extension is not the given one.
     *
     * Only validates when a file is actually present, so leaving the field blank on
     * edit (to keep the existing credential) is allowed; the required() rule handles
     * the must-upload-on-create case.
     *
     * @param  string  $extension  Required extension without the dot (e.g. 'cer').
     */
    private static function extensionRule(string $extension): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($extension): void {
            $file = is_array($value) ? reset($value) : $value;

            if ($file instanceof UploadedFile && ! self::uploadedHasExtension($value, $extension)) {
                $fail("El archivo debe tener extensión .{$extension}.");
            }
        };
    }

    /**
     * Persist FIEL credentials (encrypted) for a MUA account.
     *
     * Uses firstOrNew + setEncryptedValue so the encrypted value is set BEFORE the
     * insert — using updateOrCreate([...], []) would save the row with a null
     * credential first and violate the NOT NULL constraint. Null/blank values are
     * skipped, so on edit an untouched field leaves its stored credential intact.
     *
     * @param  MuaAccount  $account  The owning account.
     * @param  array<string, string|null>  $credentials  Map of credential type => raw value.
     */
    public static function persistCredentials(MuaAccount $account, array $credentials): void
    {
        foreach ($credentials as $type => $value) {
            if (filled($value)) {
                MuaCredential::firstOrNew([
                    'mua_account_id' => $account->id,
                    'type' => $type,
                ])->setEncryptedValue($value)->save();
            }
        }
    }

    /**
     * Define the table listing MuaAccounts.
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
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Define the detail (show) view of a MUA account.
     *
     * Shows the soldado's data and which FIEL credentials are loaded — never the
     * secret values themselves, only a present/missing indicator per component.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del soldado')
                ->columns(2)
                ->schema([
                    InfoTextEntry::make('name')->label('Nombre completo'),
                    InfoTextEntry::make('email')->label('Correo electrónico')->placeholder('—'),
                    InfoTextEntry::make('rfc')->label('RFC'),
                    IconEntry::make('is_active')->label('Activo')->boolean(),
                    InfoTextEntry::make('active_submissions')->label('En proceso')->numeric(),
                    InfoTextEntry::make('created_at')->label('Registrado')->dateTime('d/m/Y H:i'),
                ]),

            Section::make('Credenciales FIEL (e.firma)')
                ->description('Por seguridad no se muestran los valores; solo si están cargados.')
                ->columns(3)
                ->schema([
                    IconEntry::make('cred_certificate')
                        ->label('Certificado (.cer)')
                        ->boolean()
                        ->state(fn (MuaAccount $record): bool => $record->credentials->contains('type', 'certificate')),

                    IconEntry::make('cred_private_key')
                        ->label('Llave privada (.key)')
                        ->boolean()
                        ->state(fn (MuaAccount $record): bool => $record->credentials->contains('type', 'private_key')),

                    IconEntry::make('cred_password')
                        ->label('Contraseña')
                        ->boolean()
                        ->state(fn (MuaAccount $record): bool => $record->credentials->contains('type', 'password')),
                ]),
        ]);
    }

    /**
     * Define the resource pages.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMuaAccounts::route('/'),
            'create' => Pages\CreateMuaAccount::route('/create'),
            'view' => Pages\ViewMuaAccount::route('/{record}'),
            'edit' => Pages\EditMuaAccount::route('/{record}/edit'),
        ];
    }
}
