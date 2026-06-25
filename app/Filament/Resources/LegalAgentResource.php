<?php

namespace App\Filament\Resources;

use App\Enums\LegalAgentTypeEnum;
use App\Filament\Resources\LegalAgentResource\Pages;
use App\Filament\Resources\LegalAgentResource\RelationManagers\RegistrationsRelationManager;
use App\Models\LegalAgent;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Catalog of legal representatives and commissaries assignable to actas constitutivas.
 *
 * Foreign-owned companies require a legal representative and a commissary in the acta.
 * This resource lets the notary team maintain a reusable catalog, and each profile's
 * detail view shows which actas it is assigned to and the share percentage it holds.
 */
class LegalAgentResource extends Resource
{
    /**
     * @var class-string<LegalAgent>
     */
    protected static ?string $model = LegalAgent::class;

    protected static ?string $navigationLabel = 'Representantes y comisarios';

    protected static ?string $modelLabel = 'representante / comisario';

    protected static ?string $pluralModelLabel = 'representantes y comisarios';

    /**
     * Navigation group — must match parent type exactly: string | UnitEnum | null.
     */
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 20;

    /**
     * Return the icon for this resource in the sidebar.
     */
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-identification';
    }

    /**
     * Restrict access to the notary team roles.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasAnyRole(['super_admin', 'notario', 'asistente_notario']) ?? false;
    }

    /**
     * Define the create/edit form for a legal agent profile.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Perfil')
                ->columns(2)
                ->schema([
                    Select::make('type')
                        ->label('Tipo')
                        ->options(LegalAgentTypeEnum::options())
                        ->required()
                        ->native(false),

                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->helperText('Desactivar para ocultarlo de nuevas asignaciones sin borrar su historial.'),

                    TextInput::make('name')
                        ->label('Nombre completo')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    TextInput::make('nationality')
                        ->label('Nacionalidad')
                        ->default('mexicana')
                        ->maxLength(255),

                    DatePicker::make('birthdate')
                        ->label('Fecha de nacimiento')
                        ->native(false)
                        ->displayFormat('d/m/Y'),

                    TextInput::make('rfc')
                        ->label('RFC')
                        ->maxLength(13)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null ? strtoupper($state) : null),

                    TextInput::make('curp')
                        ->label('CURP')
                        ->maxLength(18)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null ? strtoupper($state) : null),

                    TextInput::make('birthplace')
                        ->label('Lugar de nacimiento')
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->maxLength(255),

                    TextInput::make('phone')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(30),

                    Textarea::make('address')
                        ->label('Domicilio')
                        ->rows(2)
                        ->columnSpanFull(),

                    Textarea::make('notes')
                        ->label('Notas internas')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Define the catalog listing table.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (LegalAgentTypeEnum $state): string => $state->label())
                    ->color(fn (LegalAgentTypeEnum $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('rfc')
                    ->label('RFC')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('nationality')
                    ->label('Nacionalidad')
                    ->toggleable(),

                TextColumn::make('registrations_count')
                    ->label('Actas asignadas')
                    ->counts('registrations')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(LegalAgentTypeEnum::options()),

                SelectFilter::make('is_active')
                    ->label('Estado')
                    ->options([
                        1 => 'Activos',
                        0 => 'Inactivos',
                    ]),
            ])
            ->defaultSort('name')
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Define the read-only detail (infolist) shown on the View page.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Perfil')
                ->columns(3)
                ->schema([
                    TextEntry::make('type')
                        ->label('Tipo')
                        ->badge()
                        ->formatStateUsing(fn (LegalAgentTypeEnum $state): string => $state->label())
                        ->color(fn (LegalAgentTypeEnum $state): string => $state->color()),

                    TextEntry::make('name')->label('Nombre completo')->columnSpan(2),
                    TextEntry::make('nationality')->label('Nacionalidad')->placeholder('—'),
                    TextEntry::make('rfc')->label('RFC')->placeholder('—'),
                    TextEntry::make('curp')->label('CURP')->placeholder('—'),
                    TextEntry::make('birthdate')->label('Fecha de nacimiento')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('birthplace')->label('Lugar de nacimiento')->placeholder('—'),
                    TextEntry::make('email')->label('Correo')->placeholder('—'),
                    TextEntry::make('phone')->label('Teléfono')->placeholder('—'),
                    TextEntry::make('address')->label('Domicilio')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('notes')->label('Notas internas')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Relation managers shown on the View/Edit pages.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            RegistrationsRelationManager::class,
        ];
    }

    /**
     * Define the resource pages.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLegalAgents::route('/'),
            'create' => Pages\CreateLegalAgent::route('/create'),
            'view' => Pages\ViewLegalAgent::route('/{record}'),
            'edit' => Pages\EditLegalAgent::route('/{record}/edit'),
        ];
    }
}
