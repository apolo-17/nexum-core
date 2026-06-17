<?php

namespace App\Filament\Resources;

use App\Enums\EfirmaAppointmentStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Filament\Resources\RegistrationResource\Pages;
use App\Filament\Resources\RegistrationResource\RelationManagers;
use App\Models\Registration;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Filament resource for managing company incorporation expedients.
 *
 * The central resource of the Nexum dashboard — lists all client registrations,
 * allows stage and status management, and links to related shareholders,
 * legal names, documents, tasks, and notes via relation managers.
 */
class RegistrationResource extends Resource
{
    protected static ?string $model = Registration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?string $navigationLabel = 'Expedientes';

    protected static ?string $modelLabel = 'Expediente';

    protected static ?string $pluralModelLabel = 'Expedientes';

    protected static ?int $navigationSort = 1;

    /**
     * Define the form schema used for creating and editing registrations.
     *
     * @param  Schema  $schema
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del cliente')
                ->columns(2)
                ->schema([
                    TextInput::make('singapur_client_code')
                        ->label('Código de cliente (Singapur)')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    TextInput::make('singapur_package_id')
                        ->label('ID de paquete ZIP')
                        ->maxLength(255),
                ]),

            Section::make('Empresa')
                ->columns(2)
                ->schema([
                    Select::make('company_type')
                        ->label('Tipo de sociedad')
                        ->options([
                            'SA de CV'   => 'SA de CV',
                            'SRL de CV'  => 'SRL de CV',
                            'SAPI de CV' => 'SAPI de CV',
                        ]),

                    TextInput::make('rfc')
                        ->label('RFC')
                        ->maxLength(13),

                    DateTimePicker::make('efirma_appointment_at')
                        ->label('Cita e.firma SAT')
                        ->nullable(),
                ]),

            Section::make('Estado del expediente')
                ->columns(2)
                ->schema([
                    Select::make('stage')
                        ->label('Etapa actual')
                        ->options(
                            collect(RegistrationStageEnum::cases())
                                ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                        )
                        ->required(),

                    Select::make('status')
                        ->label('Estatus')
                        ->options(
                            collect(RegistrationStatusEnum::cases())
                                ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                        )
                        ->required(),
                ]),

            Section::make('Asignación')
                ->columns(2)
                ->schema([
                    Select::make('assigned_notario_id')
                        ->label('Notario asignado')
                        ->options(
                            User::role('notario')->pluck('name', 'id')
                        )
                        ->searchable()
                        ->nullable(),

                    Select::make('assigned_asistente_id')
                        ->label('Asistente asignado')
                        ->options(
                            User::role('asistente_notario')->pluck('name', 'id')
                        )
                        ->searchable()
                        ->nullable(),
                ]),
        ]);
    }

    /**
     * Define the infolist displayed on the ViewRegistration page.
     *
     * Shows the full picture of an expedient at a glance: company data, stage
     * progress, assignment, Singapur identifiers, and e.firma details when relevant.
     *
     * @param  Schema  $schema
     * @return Schema
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            // ----------------------------------------------------------------
            // Company overview
            // ----------------------------------------------------------------
            Section::make('Empresa')
                ->columns(3)
                ->schema([
                    TextEntry::make('legalNames.name')
                        ->label('Nombre de la empresa')
                        ->state(function (Registration $record): string {
                            return $record->legalNames()
                                ->where('priority', 1)
                                ->value('name') ?? '—';
                        })
                        ->columnSpan(2),

                    TextEntry::make('company_type')
                        ->label('Tipo de sociedad')
                        ->placeholder('—'),

                    TextEntry::make('singapur_client_code')
                        ->label('Código cliente (Singapur)'),

                    TextEntry::make('rfc')
                        ->label('RFC')
                        ->placeholder('Pendiente'),

                    TextEntry::make('created_at')
                        ->label('Fecha de ingreso')
                        ->date('d/m/Y'),
                ]),

            // ----------------------------------------------------------------
            // Stage progress — visual pipeline of all 8 stages
            // ----------------------------------------------------------------
            Section::make('Progreso del expediente')
                ->schema([
                    TextEntry::make('stage_pipeline')
                        ->label('')
                        ->state(function (Registration $record): string {
                            $stages   = RegistrationStageEnum::orderedStages();
                            $current  = $record->stage;
                            $lines    = [];

                            foreach ($stages as $stage) {
                                $isCurrent = $stage === $current;
                                $isPast    = array_search($stage, $stages) < array_search($current, $stages);

                                if ($isCurrent) {
                                    $lines[] = '▶  ' . $stage->label() . '  ← actual';
                                } elseif ($isPast) {
                                    $lines[] = '✓  ' . $stage->label();
                                } else {
                                    $lines[] = '○  ' . $stage->label();
                                }
                            }

                            return implode("\n", $lines);
                        })
                        ->html(false),
                ]),

            // ----------------------------------------------------------------
            // Status & assignment
            // ----------------------------------------------------------------
            Section::make('Estado y asignación')
                ->columns(2)
                ->schema([
                    TextEntry::make('status')
                        ->label('Estatus')
                        ->badge()
                        ->formatStateUsing(fn (RegistrationStatusEnum $state) => $state->label())
                        ->color(fn (RegistrationStatusEnum $state): string => match ($state) {
                            RegistrationStatusEnum::ACTIVE    => 'success',
                            RegistrationStatusEnum::ON_HOLD   => 'warning',
                            RegistrationStatusEnum::CANCELLED => 'danger',
                            RegistrationStatusEnum::COMPLETED => 'gray',
                        }),

                    TextEntry::make('stage')
                        ->label('Etapa actual')
                        ->badge()
                        ->formatStateUsing(fn (RegistrationStageEnum $state) => $state->label())
                        ->color('info'),

                    TextEntry::make('notario.name')
                        ->label('Notario asignado')
                        ->placeholder('Sin asignar'),

                    TextEntry::make('asistente.name')
                        ->label('Asistente asignado')
                        ->placeholder('Sin asignar'),
                ]),

            // ----------------------------------------------------------------
            // E.firma section — visible only at the relevant stage
            // ----------------------------------------------------------------
            Section::make('Cita e.firma SAT')
                ->columns(2)
                ->visible(fn (Registration $record): bool => (
                    $record->stage === RegistrationStageEnum::EFIRMA_APPOINTMENT
                ))
                ->schema([
                    TextEntry::make('efirma_status')
                        ->label('Estado de la cita')
                        ->badge()
                        ->formatStateUsing(
                            fn (?EfirmaAppointmentStatusEnum $state) => $state?->label() ?? 'Sin solicitar'
                        )
                        ->color(fn (?EfirmaAppointmentStatusEnum $state): string => match ($state) {
                            EfirmaAppointmentStatusEnum::PENDING_SCHEDULING => 'warning',
                            EfirmaAppointmentStatusEnum::SCHEDULED          => 'info',
                            EfirmaAppointmentStatusEnum::ATTENDED_APPROVED  => 'success',
                            EfirmaAppointmentStatusEnum::ATTENDED_REJECTED  => 'danger',
                            EfirmaAppointmentStatusEnum::NO_SHOW            => 'danger',
                            default                                         => 'gray',
                        }),

                    TextEntry::make('efirma_appointment_at')
                        ->label('Fecha de cita')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('Sin confirmar'),

                    IconEntry::make('efirma_key_path')
                        ->label('.key subido')
                        ->boolean()
                        ->trueIcon('heroicon-o-check-circle')
                        ->falseIcon('heroicon-o-x-circle')
                        ->state(fn (Registration $record): bool => filled($record->efirma_key_path)),

                    IconEntry::make('efirma_cer_path')
                        ->label('.cer subido')
                        ->boolean()
                        ->trueIcon('heroicon-o-check-circle')
                        ->falseIcon('heroicon-o-x-circle')
                        ->state(fn (Registration $record): bool => filled($record->efirma_cer_path)),
                ]),
        ]);
    }

    /**
     * Define the table columns and filters for the registrations list.
     *
     * @param  Table  $table
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('singapur_client_code')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('company_type')
                    ->label('Tipo de sociedad')
                    ->placeholder('—'),

                BadgeColumn::make('stage')
                    ->label('Etapa')
                    ->formatStateUsing(fn (RegistrationStageEnum $state) => $state->label())
                    ->colors([
                        'gray'    => RegistrationStageEnum::DATA_RECEIVED->value,
                        'warning' => RegistrationStageEnum::IDENTITY_VALIDATION->value,
                        'info'    => [
                            RegistrationStageEnum::LEGAL_NAME->value,
                            RegistrationStageEnum::INCORPORATION->value,
                            RegistrationStageEnum::BANK_ACCOUNT->value,
                            RegistrationStageEnum::SAT_REGISTRATION->value,
                            RegistrationStageEnum::EFIRMA_APPOINTMENT->value,
                        ],
                        'success' => RegistrationStageEnum::COMPLETED->value,
                    ]),

                BadgeColumn::make('status')
                    ->label('Estatus')
                    ->formatStateUsing(fn (RegistrationStatusEnum $state) => $state->label())
                    ->colors([
                        'success' => RegistrationStatusEnum::ACTIVE->value,
                        'warning' => RegistrationStatusEnum::ON_HOLD->value,
                        'danger'  => RegistrationStatusEnum::CANCELLED->value,
                        'gray'    => RegistrationStatusEnum::COMPLETED->value,
                    ]),

                TextColumn::make('notario.name')
                    ->label('Notario')
                    ->placeholder('—'),

                TextColumn::make('tasks_pending_count')
                    ->label('Tareas pendientes')
                    ->badge()
                    ->color('warning'),

                TextColumn::make('created_at')
                    ->label('Fecha de ingreso')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('stage')
                    ->label('Etapa')
                    ->options(
                        collect(RegistrationStageEnum::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                    ),

                SelectFilter::make('status')
                    ->label('Estatus')
                    ->options(
                        collect(RegistrationStatusEnum::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                    ),

                SelectFilter::make('assigned_notario_id')
                    ->label('Notario')
                    ->options(User::role('notario')->pluck('name', 'id')),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Return the relation managers attached to the view/edit pages.
     *
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\ShareholdersRelationManager::class,
            RelationManagers\LegalNamesRelationManager::class,
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\TasksRelationManager::class,
            RelationManagers\NotesRelationManager::class,
            RelationManagers\StageTransitionsRelationManager::class,
        ];
    }

    /**
     * Return the pages registered for this resource.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        // CreateRegistration is intentionally excluded — expedients are created
        // automatically when the Singapur relay posts a webhook event.
        // Manual creation is not supported to enforce data integrity.
        return [
            'index' => Pages\ListRegistrations::route('/'),
            'view'  => Pages\ViewRegistration::route('/{record}'),
            'edit'  => Pages\EditRegistration::route('/{record}/edit'),
        ];
    }
}
