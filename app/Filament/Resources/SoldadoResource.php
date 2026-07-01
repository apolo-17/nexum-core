<?php

namespace App\Filament\Resources;

use App\Enums\AppointmentStatusEnum;
use App\Enums\LegalNameStatusEnum;
use App\Filament\Resources\SoldadoResource\Pages;
use App\Models\Soldado;
use App\Models\SoldadoCredential;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry as InfoTextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Filament resource for managing soldados — the contracted people Nexum uses as MUA
 * holders and/or legal representatives.
 *
 * Only accessible to super_admin. The form captures identity, INE, capability flags
 * and (optionally) FIEL credentials. Dashboard access is granted via a separate
 * action that creates a linked User and emails an invitation.
 */
class SoldadoResource extends Resource
{
    /**
     * @var class-string<Soldado>
     */
    protected static ?string $model = Soldado::class;

    protected static ?string $navigationLabel = 'Soldados';

    protected static ?string $modelLabel = 'Soldado';

    protected static ?string $pluralModelLabel = 'Soldados';

    /**
     * Navigation group — must match parent type exactly: string | UnitEnum | null.
     */
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 5;

    /**
     * Return the icon for this resource in the sidebar.
     */
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-user-group';
    }

    /**
     * Restrict access to super_admin only.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Define the form for creating / editing a soldado.
     *
     * FIEL fields (certificate_file, private_key_file, private_key_password) are
     * virtual — they do not map to columns on soldados. The page lifecycle hooks
     * base64-encode the files and persist them to soldado_credentials. INE files map
     * directly to their path columns, so Filament stores them automatically.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identidad')
                ->description('Para registrar solo se piden el nombre y el correo. Al guardar se le envía una invitación para que el soldado defina su contraseña y complete su perfil.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre completo')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Correo electrónico')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->unique(ignoreRecord: true)
                        ->validationMessages(['unique' => 'Este correo ya está registrado para otro soldado.']),

                    // The fields below are completed later (in edit), not at registration.
                    TextInput::make('phone')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(30)
                        ->hiddenOn('create'),

                    TextInput::make('rfc')
                        ->label('RFC')
                        ->length(13)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null ? strtoupper($state) : null)
                        ->unique(ignoreRecord: true)
                        ->hiddenOn('create'),

                    TextInput::make('curp')
                        ->label('CURP')
                        ->length(18)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null ? strtoupper($state) : null)
                        ->hiddenOn('create'),

                    DatePicker::make('birthdate')
                        ->label('Fecha de nacimiento')
                        ->native(false)
                        ->hiddenOn('create'),

                    TextInput::make('birthplace')
                        ->label('Lugar de nacimiento')
                        ->maxLength(255)
                        ->hiddenOn('create'),

                    Textarea::make('address')
                        ->label('Domicilio')
                        ->columnSpanFull()
                        ->rows(2)
                        ->hiddenOn('create'),
                ]),

            Section::make('INE')
                ->description('Sube ambos lados de la credencial de elector.')
                ->columns(2)
                ->hiddenOn('create')
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

            Section::make('Capacidades')
                ->description('Define para qué se usará el soldado (MUA y/o representación). La FIEL se carga después al completar el perfil.')
                ->columns(2)
                ->schema([
                    Toggle::make('available_for_mua')
                        ->label('Disponible para MUA (presta su FIEL)')
                        ->live()
                        ->default(false),

                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->helperText('Desactivar para excluirlo sin darlo de baja.'),

                    Toggle::make('available_as_legal_representative')
                        ->label('Disponible como representante legal')
                        ->default(false),

                    Toggle::make('available_as_commissary')
                        ->label('Disponible como comisario')
                        ->default(false),
                ]),

            Section::make('FIEL (e.firma)')
                ->description('Sube los archivos de la e.firma tal cual (.cer y .key). Se guardan cifrados. Deja los campos vacíos para conservar las credenciales actuales.')
                ->hiddenOn('create')
                ->visible(fn (Get $get): bool => (bool) $get('available_for_mua'))
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
     * Read an uploaded FIEL file from the form state and return its base64 content.
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
     * Build a validation rule that rejects uploads whose extension is not the given one.
     *
     * Only validates when a file is present, so leaving the field blank on edit
     * (to keep the existing credential) is allowed.
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
     * Persist FIEL credentials (encrypted) for a soldado.
     *
     * Uses firstOrNew + setEncryptedValue so the encrypted value is set before insert.
     * Null/blank values are skipped, so on edit an untouched field is left intact.
     *
     * @param  Soldado  $soldado  The owning soldado.
     * @param  array<string, string|null>  $credentials  Map of credential type => raw value.
     */
    public static function persistCredentials(Soldado $soldado, array $credentials): void
    {
        foreach ($credentials as $type => $value) {
            if (filled($value)) {
                SoldadoCredential::firstOrNew([
                    'soldado_id' => $soldado->id,
                    'type' => $type,
                ])->setEncryptedValue($value)->save();
            }
        }
    }

    /**
     * Grant dashboard access to a soldado: create/link a User and email an invitation.
     *
     * Reuses the password-reset broker so the invitee lands on Filament's branded
     * "set password" page. If a user with the soldado's email already exists, it is
     * linked instead of creating a duplicate.
     *
     * @param  Soldado  $soldado  The soldado to grant access to.
     */
    public static function grantAccess(Soldado $soldado): void
    {
        $user = User::firstOrCreate(
            ['email' => $soldado->email],
            ['name' => $soldado->name, 'password' => Str::random(40)],
        );

        if (! $user->hasRole('soldado')) {
            $user->assignRole('soldado');
        }

        $soldado->update(['user_id' => $user->id]);

        $token = Password::broker()->createToken($user);
        $user->notify(new AccountInvitationNotification($token, 'Soldado'));
    }

    /**
     * Define the table listing soldados.
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
                    ->label('Correo')
                    ->searchable(),

                TextColumn::make('rfc')
                    ->label('RFC')
                    ->searchable(),

                IconColumn::make('available_for_mua')
                    ->label('MUA')
                    ->boolean(),

                IconColumn::make('available_as_legal_representative')
                    ->label('Rep. legal')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),

                IconColumn::make('user_id')
                    ->label('Acceso')
                    ->boolean()
                    ->state(fn (Soldado $record): bool => $record->user_id !== null),
            ])
            ->actions([
                Action::make('grantAccess')
                    ->label('Dar acceso')
                    ->icon('heroicon-o-envelope')
                    ->color('success')
                    ->visible(fn (Soldado $record): bool => $record->user_id === null)
                    ->requiresConfirmation()
                    ->modalDescription('Se creará una cuenta y se enviará un correo de bienvenida para que el soldado defina su contraseña.')
                    ->action(function (Soldado $record): void {
                        try {
                            self::grantAccess($record);

                            Notification::make()
                                ->title('Invitación enviada')
                                ->body('El soldado recibirá un correo para activar su acceso.')
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('No se pudo enviar la invitación')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('resendInvitation')
                    ->label('Reenviar invitación')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->visible(fn (Soldado $record): bool => $record->user_id !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Se enviará un nuevo correo con un enlace vigente por 60 minutos para que el soldado defina su contraseña. El enlace anterior dejará de funcionar.')
                    ->action(function (Soldado $record): void {
                        try {
                            self::grantAccess($record);

                            Notification::make()
                                ->title('Invitación reenviada correctamente.')
                                ->body('El soldado recibirá un nuevo correo para completar su registro.')
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Log::error('Failed to resend soldado invitation.', [
                                'soldado_id' => $record->id,
                                'error' => $exception->getMessage(),
                            ]);

                            Notification::make()
                                ->title('No se pudo reenviar la invitación')
                                ->body('Revisa la configuración de correo (Resend).')
                                ->danger()
                                ->send();
                        }
                    }),

                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Define the detail (show) view of a soldado.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identidad')
                ->columns(2)
                ->schema([
                    InfoTextEntry::make('name')->label('Nombre completo'),
                    InfoTextEntry::make('email')->label('Correo electrónico'),
                    InfoTextEntry::make('phone')->label('Teléfono')->placeholder('—'),
                    InfoTextEntry::make('rfc')->label('RFC'),
                    InfoTextEntry::make('curp')->label('CURP')->placeholder('—'),
                    InfoTextEntry::make('birthdate')->label('Fecha de nacimiento')->date('d/m/Y')->placeholder('—'),
                ]),

            Section::make('INE')
                ->description('Credencial de elector cargada por el soldado durante su registro.')
                ->schema([
                    ViewEntry::make('ine')
                        ->hiddenLabel()
                        ->view('filament.infolists.soldado-ine'),
                ]),

            Section::make('Capacidades y estado')
                ->columns(4)
                ->schema([
                    IconEntry::make('available_for_mua')->label('MUA')->boolean(),
                    IconEntry::make('available_as_legal_representative')->label('Rep. legal')->boolean(),
                    IconEntry::make('available_as_commissary')->label('Comisario')->boolean(),
                    IconEntry::make('is_active')->label('Activo')->boolean(),
                    IconEntry::make('user_id')
                        ->label('Acceso al panel')
                        ->boolean()
                        ->state(fn (Soldado $record): bool => $record->user_id !== null),
                ]),

            Section::make('Desempeño (KPIs)')
                ->description('Reporte del soldado: empresas, citas y denominaciones.')
                ->columns(4)
                ->schema([
                    InfoTextEntry::make('kpi_companies')
                        ->label('Empresas')
                        ->state(fn (Soldado $record): int => $record->registrations()->count()),

                    InfoTextEntry::make('kpi_appointments_completed')
                        ->label('Citas agendadas')
                        ->state(fn (Soldado $record): string => $record->appointments()
                            ->where('status', AppointmentStatusEnum::SCHEDULED->value)
                            ->count()
                            .' / '.$record->appointments()->count()),

                    InfoTextEntry::make('kpi_denominations_approved')
                        ->label('Denominaciones aprobadas')
                        ->state(fn (Soldado $record): int => $record->legalNames()
                            ->where('status', LegalNameStatusEnum::APPROVED->value)
                            ->count()),

                    InfoTextEntry::make('active_submissions')
                        ->label('Denominaciones en proceso')
                        ->numeric(),
                ]),

            Section::make('FIEL (e.firma)')
                ->description('Por seguridad no se muestran los valores; solo si están cargados.')
                ->visible(fn (Soldado $record): bool => $record->available_for_mua)
                ->columns(3)
                ->schema([
                    IconEntry::make('cred_certificate')
                        ->label('Certificado (.cer)')
                        ->boolean()
                        ->state(fn (Soldado $record): bool => $record->credentials->contains('type', 'certificate')),

                    IconEntry::make('cred_private_key')
                        ->label('Llave privada (.key)')
                        ->boolean()
                        ->state(fn (Soldado $record): bool => $record->credentials->contains('type', 'private_key')),

                    IconEntry::make('cred_password')
                        ->label('Contraseña')
                        ->boolean()
                        ->state(fn (Soldado $record): bool => $record->credentials->contains('type', 'password')),
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
            'index' => Pages\ListSoldados::route('/'),
            'create' => Pages\CreateSoldado::route('/create'),
            'view' => Pages\ViewSoldado::route('/{record}'),
            'edit' => Pages\EditSoldado::route('/{record}/edit'),
        ];
    }
}
