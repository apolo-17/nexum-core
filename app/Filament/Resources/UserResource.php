<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * Filament resource for managing notary team members (users).
 *
 * Only accessible to super_admin. Lets an administrator invite new members
 * (name + email + role), edit their role, and re-send the activation email.
 * New users receive a welcome email with a link to set their own password;
 * until they do, they show as "Pendiente de activación".
 */
class UserResource extends Resource
{
    /**
     * @var class-string<User>
     */
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Usuarios';

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
        return 'heroicon-o-users';
    }

    /**
     * The roles a super_admin may assign, mapped to human-readable labels.
     *
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return [
            'super_admin' => 'Administrador',
            'notario' => 'Notario',
            'asistente_notario' => 'Asistente de notario',
            'soldado' => 'Soldado',
            'partner' => 'Partner (solo lectura)',
        ];
    }

    /**
     * Restrict the whole resource to super_admin only.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Define the form for inviting / editing a team member.
     *
     * No password field: the invitee sets their own password through the
     * emailed activation link.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del usuario')
                ->description('Al crear el usuario se le enviará un correo de bienvenida con un enlace para definir su contraseña.')
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
                        ->unique(ignoreRecord: true),

                    Select::make('assigned_roles')
                        ->label('Roles')
                        ->options(self::roleOptions())
                        ->multiple()
                        ->required()
                        ->native(false)
                        ->helperText('Puedes asignar más de un rol (p. ej. Administrador y Soldado). Si incluyes "Soldado" se crea su perfil para que complete su RFC y e.firma al activar la cuenta.'),
                ])->columns(2),
        ]);
    }

    /**
     * Define the table listing team members.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable(),

                TextColumn::make('roles.name')
                    ->label('Rol')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::roleOptions()[$state] ?? $state),

                TextColumn::make('email_verified_at')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (User $record): string => $record->email_verified_at === null ? 'Pendiente de activación' : 'Activo')
                    ->color(fn (User $record): string => $record->email_verified_at === null ? 'warning' : 'success'),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()->label('Editar'),

                    Action::make('resend_invitation')
                        ->label('Reenviar invitación')
                        ->icon('heroicon-o-envelope')
                        ->color('info')
                        ->visible(fn (User $record): bool => $record->email_verified_at === null)
                        ->requiresConfirmation()
                        ->action(function (User $record): void {
                            try {
                                self::sendInvitation($record);

                                Notification::make()
                                    ->title('Invitación reenviada correctamente.')
                                    ->success()
                                    ->send();
                            } catch (\Throwable $exception) {
                                Log::error('Failed to resend invitation email.', [
                                    'user_id' => $record->id,
                                    'error' => $exception->getMessage(),
                                ]);

                                Notification::make()
                                    ->title('No se pudo reenviar la invitación.')
                                    ->body('Revisa la configuración de correo (Resend).')
                                    ->danger()
                                    ->send();
                            }
                        }),

                    DeleteAction::make()->label('Eliminar')
                        ->visible(fn (User $record): bool => $record->id !== Auth::id()),
                ])
                    // Un solo botón "⋮" agrupa las acciones para que la tabla no se rompa
                    // ni tenga scroll lateral; despliega las acciones escondidas.
                    ->label('Acciones')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Acciones')
                    ->button()
                    ->hiddenLabel(),
            ]);
    }

    /**
     * Issue a fresh activation token and email the invitation to the user.
     *
     * Reuses the password-reset broker so the link lands on Filament's branded
     * "set password" page.
     *
     * @param  User  $user  The user to (re-)invite.
     */
    public static function sendInvitation(User $user): void
    {
        $token = Password::broker()->createToken($user);

        $user->notify(new AccountInvitationNotification(
            $token,
            self::rolesLabel($user),
        ));
    }

    /**
     * Human-readable, comma-joined label of all the user's roles (or null if none).
     */
    public static function rolesLabel(User $user): ?string
    {
        $labels = $user->roles
            ->pluck('name')
            ->map(fn (string $name): string => self::roleOptions()[$name] ?? $name)
            ->all();

        return $labels === [] ? null : implode(' y ', $labels);
    }

    /**
     * Sync a user's roles from the multi-select and, when "soldado" is among them,
     * ensure the user has a linked Soldado profile so the soldado features (Mis Citas,
     * fila virtual, e.firma) work. The profile is created minimally (name/email from the
     * user) and the soldado completes RFC/INE/e.firma through the activation link — the
     * onboarding form (CompleteRegistration) shows those fields when a linked soldado exists.
     *
     * @param  array<int, string>  $roleNames  Role slugs selected in the form.
     */
    public static function syncRolesAndSoldado(User $user, array $roleNames): void
    {
        $user->syncRoles($roleNames);

        if (! in_array('soldado', $roleNames, true)) {
            return;
        }

        // Vincula un soldado existente con el mismo correo, o crea uno nuevo. No se pisan
        // datos ya capturados; solo se garantiza el vínculo user_id ↔ soldado.
        $soldado = \App\Models\Soldado::firstOrNew(['email' => $user->email]);
        $soldado->name = $soldado->name ?: $user->name;
        $soldado->user_id = $user->id;

        if (! $soldado->exists) {
            // Nuevo perfil: habilitar e.firma para que el onboarding pida su FIEL.
            $soldado->available_for_mua = true;
        }

        $soldado->save();
    }

    /**
     * Define the resource pages.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
