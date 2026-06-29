<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MiPerfilResource\Pages;
use App\Models\Soldado;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

/**
 * Self-service profile for the logged-in soldado.
 *
 * After accepting the invitation (which sets their password), the soldado completes
 * their own registration here: phone, RFC, CURP, address, INE (both sides) and — if
 * they were enabled for MUA — their FIEL. Scoped to their own record only.
 */
class MiPerfilResource extends Resource
{
    /**
     * @var class-string<Soldado>
     */
    protected static ?string $model = Soldado::class;

    protected static ?string $navigationLabel = 'Mi perfil';

    protected static ?string $modelLabel = 'Mi perfil';

    protected static string|\UnitEnum|null $navigationGroup = 'Mi panel';

    protected static ?int $navigationSort = 0;

    /**
     * Return the icon for this resource in the sidebar.
     */
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-identification';
    }

    /**
     * Visible only to a soldado with a linked profile.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return ($user?->hasRole('soldado') ?? false) && $user?->soldado !== null;
    }

    /**
     * Point the nav item straight at the soldado's own edit page.
     */
    public static function getNavigationUrl(): string
    {
        $soldado = Auth::user()?->soldado;

        return $soldado !== null ? static::getUrl('edit', ['record' => $soldado]) : '#';
    }

    /**
     * Scope the resource to the current user's own soldado record.
     *
     * @return Builder<Soldado>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('id', Auth::user()?->soldado?->id ?? '');
    }

    /**
     * The self-service form. FIEL fields are virtual (persisted by the edit page).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mis datos')
                ->description('Completa tu información para terminar tu registro.')
                ->columns(2)
                ->schema([
                    TextInput::make('email')
                        ->label('Correo electrónico')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    TextInput::make('phone')
                        ->label('Teléfono')
                        ->tel()
                        ->required()
                        ->maxLength(30),

                    TextInput::make('rfc')
                        ->label('RFC')
                        ->length(13)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null ? strtoupper($state) : null)
                        ->unique(ignoreRecord: true),

                    TextInput::make('curp')
                        ->label('CURP')
                        ->length(18)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null ? strtoupper($state) : null),

                    DatePicker::make('birthdate')
                        ->label('Fecha de nacimiento')
                        ->native(false),

                    TextInput::make('birthplace')
                        ->label('Lugar de nacimiento')
                        ->maxLength(255),

                    Textarea::make('address')
                        ->label('Domicilio')
                        ->columnSpanFull()
                        ->rows(2),
                ]),

            Section::make('INE')
                ->description('Sube ambos lados de tu credencial de elector.')
                ->columns(2)
                ->schema([
                    FileUpload::make('ine_front_path')
                        ->label('INE — anverso')
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                        ->disk(config('filesystems.default'))
                        ->directory('soldados/ine')
                        ->visibility('private')
                        ->maxSize(4096),

                    FileUpload::make('ine_back_path')
                        ->label('INE — reverso')
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                        ->disk(config('filesystems.default'))
                        ->directory('soldados/ine')
                        ->visibility('private')
                        ->maxSize(4096),
                ]),

            Section::make('FIEL (e.firma)')
                ->description('Solo si fuiste habilitado para MUA. Sube tu .cer y .key tal cual; se guardan cifrados. Deja vacío para conservar lo ya cargado.')
                ->visible(fn (Soldado $record): bool => $record->available_for_mua)
                ->schema([
                    FileUpload::make('certificate_file')
                        ->label('Certificado (.cer)')
                        ->storeFiles(false)
                        ->maxSize(2048)
                        ->rules([fn (): Closure => self::extensionRule('cer')]),

                    FileUpload::make('private_key_file')
                        ->label('Llave privada (.key)')
                        ->storeFiles(false)
                        ->maxSize(2048)
                        ->rules([fn (): Closure => self::extensionRule('key')]),

                    TextInput::make('private_key_password')
                        ->label('Contraseña de la llave privada')
                        ->password()
                        ->revealable(),
                ])->columns(1),
        ]);
    }

    /**
     * Reject an upload whose extension is not the given one (only when a file is present).
     *
     * @param  string  $extension  Required extension without the dot (e.g. 'cer').
     */
    private static function extensionRule(string $extension): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($extension): void {
            $file = is_array($value) ? reset($value) : $value;

            if ($file instanceof UploadedFile
                && strtolower($file->getClientOriginalExtension()) !== strtolower($extension)) {
                $fail("El archivo debe tener extensión .{$extension}.");
            }
        };
    }

    /**
     * Define the resource pages — edit only (the soldado's own record).
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'edit' => Pages\EditMiPerfil::route('/{record}/edit'),
        ];
    }
}
