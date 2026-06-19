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
        return $schema->columns(3)->components([
            // ----------------------------------------------------------------
            // Row 1 — Hero header: company identity + status + assignment.
            // Full-width so the user immediately knows who and what they are
            // looking at before scrolling to the pipeline.
            // ----------------------------------------------------------------
            Section::make('Empresa')
                ->columnSpan(3)
                ->columns(4)
                ->schema([
                    TextEntry::make('legal_name_primary')
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

                    TextEntry::make('notario.name')
                        ->label('Notario asignado')
                        ->placeholder('Sin asignar'),

                    TextEntry::make('asistente.name')
                        ->label('Asistente asignado')
                        ->placeholder('Sin asignar'),

                    TextEntry::make('singapur_client_code')
                        ->label('Código cliente'),

                    TextEntry::make('created_at')
                        ->label('Fecha de ingreso')
                        ->date('d/m/Y'),
                ]),

            // ----------------------------------------------------------------
            // Row 2 — Left 2/3: visual stage pipeline.
            // The stepper renders completed stages in green, the current stage
            // in blue with an "etapa actual" pill, and pending stages in gray.
            // Using html(true) avoids creating a separate Blade view.
            // ----------------------------------------------------------------
            Section::make('Pipeline de etapas')
                ->columnSpan(2)
                ->schema([
                    TextEntry::make('stage_pipeline')
                        ->label('')
                        ->state(function (Registration $record): string {
                            $stages       = RegistrationStageEnum::orderedStages();
                            $currentValue = $record->stage->value;
                            $currentIndex = -1;

                            // Resolve current index by value to avoid enum identity issues.
                            foreach ($stages as $i => $s) {
                                if ($s->value === $currentValue) {
                                    $currentIndex = $i;
                                    break;
                                }
                            }

                            $lastIndex = array_key_last($stages);
                            $items     = [];

                            foreach ($stages as $i => $stage) {
                                $isDone    = $i < $currentIndex;
                                $isCurrent = $i === $currentIndex;
                                $isLast    = $i === $lastIndex;

                                if ($isDone) {
                                    $circle  = 'background:#16a34a;border-color:#16a34a;color:#fff;';
                                    $symbol  = '✓';
                                    $label   = 'color:#374151;';
                                    $weight  = 'font-weight:400;';
                                } elseif ($isCurrent) {
                                    $circle  = 'background:#185FA5;border-color:#185FA5;color:#fff;';
                                    $symbol  = '▶';
                                    $label   = 'color:#185FA5;';
                                    $weight  = 'font-weight:600;';
                                } else {
                                    $circle  = 'background:transparent;border-color:#d1d5db;color:#9ca3af;';
                                    $symbol  = '·';
                                    $label   = 'color:#9ca3af;';
                                    $weight  = 'font-weight:400;';
                                }

                                $badge = $isCurrent
                                    ? '<span style="font-size:11px;background:#eff6ff;color:#185FA5;padding:2px 8px;border-radius:10px;margin-left:8px;">etapa actual</span>'
                                    : '';

                                $connector = $isLast
                                    ? ''
                                    : '<div style="width:2px;height:14px;background:#e5e7eb;margin-left:11px;"></div>';

                                $items[] = "
                                    <div>
                                        <div style='display:flex;align-items:center;gap:12px;'>
                                            <div style='width:24px;height:24px;border-radius:50%;border:2px solid;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;{$circle}'>{$symbol}</div>
                                            <span style='font-size:14px;{$label}{$weight}'>" . e($stage->label()) . "{$badge}</span>
                                        </div>
                                        {$connector}
                                    </div>
                                ";
                            }

                            return '<div style="padding:4px 0;">' . implode('', $items) . '</div>';
                        })
                        ->html(true),
                ]),

            // ----------------------------------------------------------------
            // Row 2 — Right 1/3: secondary details.
            // RFC, folder and timestamps that matter but don't need top billing.
            // ----------------------------------------------------------------
            Section::make('Detalles')
                ->columnSpan(1)
                ->schema([
                    TextEntry::make('rfc')
                        ->label('RFC')
                        ->placeholder('Pendiente'),

                    TextEntry::make('singapur_folder_name')
                        ->label('Carpeta relay')
                        ->placeholder('—'),

                    TextEntry::make('completed_at')
                        ->label('Completado el')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('En proceso')
                        ->visible(fn (Registration $record): bool => $record->completed_at !== null),
                ]),

            // ----------------------------------------------------------------
            // Row 3 — E.firma section, only at the EFIRMA_APPOINTMENT stage.
            // Full-width so all four indicators lay out comfortably.
            // ----------------------------------------------------------------
            Section::make('Cita e.firma SAT')
                ->columnSpan(3)
                ->columns(4)
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
