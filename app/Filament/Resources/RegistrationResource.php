<?php

namespace App\Filament\Resources;

use App\Enums\DocumentTypeEnum;
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
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
     * Override the base Eloquent query to eager-load the primary legal name.
     *
     * Prevents N+1 queries when the table column renders the company display name
     * for every row. Only priority-1 records are fetched per registration.
     *
     * @return Builder<Registration>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('primaryLegalName');
    }

    /**
     * Define the form schema used for creating and editing registrations.
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
                            'SA de CV' => 'SA de CV',
                            'SRL de CV' => 'SRL de CV',
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
     * Layout (top to bottom):
     *   1. Pipeline — full-width horizontal stepper so the team sees progress immediately.
     *   2. Empresa  — 5-column card merging company identity, assignment, and key references.
     *   3. Cita e.firma SAT — contextual block, only visible at the e.firma stage.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            // ----------------------------------------------------------------
            // Row 1 — Horizontal pipeline stepper.
            // First thing visible so the team immediately knows the current stage.
            // "✓ Confirmar etapa" button lives in the page header (AdvanceStageAction).
            // ----------------------------------------------------------------
            Section::make('Pipeline')
                ->columnSpan(3)
                ->schema([
                    TextEntry::make('stage_pipeline')
                        ->label('')
                        ->state(function (Registration $record): string {
                            $stages = RegistrationStageEnum::orderedStages();
                            $currentValue = $record->stage->value;
                            $currentIndex = -1;

                            foreach ($stages as $i => $s) {
                                if ($s->value === $currentValue) {
                                    $currentIndex = $i;

                                    break;
                                }
                            }

                            $total = count($stages);
                            $lastIndex = $total - 1;
                            $dots = [];
                            $labels = [];

                            foreach ($stages as $i => $stage) {
                                $isDone = $i < $currentIndex;
                                $isCurrent = $i === $currentIndex;
                                $isLast = $i === $lastIndex;

                                // Circle styles.
                                if ($isDone) {
                                    $bg = '#16a34a';
                                    $border = '#16a34a';
                                    $color = '#fff';
                                    $symbol = '✓';
                                } elseif ($isCurrent) {
                                    $bg = '#185FA5';
                                    $border = '#185FA5';
                                    $color = '#fff';
                                    $symbol = '▶';
                                } else {
                                    $bg = '#fff';
                                    $border = '#d1d5db';
                                    $color = '#9ca3af';
                                    $symbol = (string) ($i + 1);
                                }

                                // Label styles.
                                if ($isCurrent) {
                                    $lblColor = '#185FA5';
                                    $lblWeight = 'font-weight:600;';
                                } elseif ($isDone) {
                                    $lblColor = '#374151';
                                    $lblWeight = 'font-weight:400;';
                                } else {
                                    $lblColor = '#9ca3af';
                                    $lblWeight = 'font-weight:400;';
                                }

                                // Connector line (not rendered after the last step).
                                $connector = $isLast
                                    ? ''
                                    : '<div style="flex:1;height:2px;background:'.($isDone ? '#16a34a' : '#e5e7eb').';margin-top:-1px;"></div>';

                                $dots[] = "
                                    <div style='display:flex;align-items:center;flex:1;min-width:0;'>
                                        <div style='display:flex;flex-direction:column;align-items:center;flex-shrink:0;'>
                                            <div style='width:26px;height:26px;border-radius:50%;border:2px solid {$border};background:{$bg};color:{$color};display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;line-height:1;'>{$symbol}</div>
                                        </div>
                                        {$connector}
                                    </div>
                                ";

                                $shortLabel = e($stage->shortLabel());
                                $labels[] = "<div style='flex:1;text-align:center;padding-top:6px;min-width:0;overflow:hidden;'>"
                                    ."<span style='font-size:10px;{$lblWeight}color:{$lblColor};white-space:nowrap;' title='".e($stage->label())."'>{$shortLabel}</span>"
                                    .'</div>';
                            }

                            $dotsRow = '<div style="display:flex;align-items:center;width:100%;">'.implode('', $dots).'</div>';
                            $labelsRow = '<div style="display:flex;width:100%;">'.implode('', $labels).'</div>';

                            // Status banner below the stepper.
                            $banner = '<div style="margin-top:14px;padding:8px 14px;background:#eff6ff;border-left:3px solid #185FA5;border-radius:4px;display:flex;align-items:center;justify-content:space-between;">'
                                .'<span style="font-size:13px;color:#185FA5;font-weight:600;">Etapa actual: '.e($record->stage->label()).'</span>'
                                .'<span style="font-size:12px;color:#6b7280;">Usa el botón ✓ en la parte superior para confirmar la etapa</span>'
                                .'</div>';

                            return '<div style="padding:4px 0 2px;">'.$dotsRow.$labelsRow.$banner.'</div>';
                        })
                        ->html(true),
                ]),

            // ----------------------------------------------------------------
            // Row 2 — Company card (5-column grid).
            // Merges company identity, assignment, and Singapur references into
            // one cohesive block. Two rows of five:
            //   Row A: company name (×2) | type | status badge | RFC
            //   Row B: notario | asistente | code | date received | completed date
            // ----------------------------------------------------------------
            Section::make('Empresa')
                ->columnSpan(3)
                ->columns(5)
                ->schema([
                    // --- Row A: identity & status ---
                    TextEntry::make('legal_name_primary')
                        ->label('Nombre de la empresa')
                        ->state(function (Registration $record): string {
                            return $record->primaryLegalName?->name ?? '—';
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
                            RegistrationStatusEnum::ACTIVE => 'success',
                            RegistrationStatusEnum::ON_HOLD => 'warning',
                            RegistrationStatusEnum::CANCELLED => 'danger',
                            RegistrationStatusEnum::COMPLETED => 'gray',
                        }),

                    TextEntry::make('rfc')
                        ->label('RFC')
                        ->placeholder('Pendiente'),

                    // --- Row B: people & references ---
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

                    TextEntry::make('completed_at')
                        ->label('Completado el')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('En proceso'),
                ]),

            // ----------------------------------------------------------------
            // Row 3 — Acta preparation context block.
            // Visible from ACTA_PREPARATION onwards so the notary can verify
            // the corporate data before generating the draft.
            // ----------------------------------------------------------------
            Section::make('Datos para el Acta Constitutiva')
                ->columnSpan(3)
                ->columns(3)
                ->visible(fn (Registration $record): bool => in_array(
                    $record->stage,
                    [
                        RegistrationStageEnum::ACTA_PREPARATION,
                        RegistrationStageEnum::PARTNER_SIGNATURE,
                        RegistrationStageEnum::INCORPORATION,
                        RegistrationStageEnum::TAX_ADDRESS,
                        RegistrationStageEnum::SAT_REGISTRATION,
                        RegistrationStageEnum::EFIRMA_APPOINTMENT,
                        RegistrationStageEnum::COMPLETED,
                    ],
                    true,
                ))
                ->schema([
                    TextEntry::make('company_object')
                        ->label('Objeto social')
                        ->placeholder('⚠️ Sin objeto social — debe llegar en el webhook o editarse manualmente')
                        ->columnSpan(2),

                    TextEntry::make('capital_social')
                        ->label('Capital social')
                        ->money('MXN')
                        ->placeholder('⚠️ Sin capital social — default $50,000 MXN'),

                    TextEntry::make('acta_draft_status')
                        ->label('Borrador del acta')
                        ->columnSpan(3)
                        ->state(function (Registration $record): string {
                            $draft = $record->documents()
                                ->where('type', DocumentTypeEnum::ACTA_DRAFT->value)
                                ->latest()
                                ->first();

                            if ($draft === null) {
                                return '⚠️ Sin borrador — usa el botón "📋 Preparar borrador del acta" para compilarlo';
                            }

                            $ts = $draft->updated_at?->format('d/m/Y H:i') ?? '—';

                            return "✓ Borrador compilado el {$ts}. Puedes ver el JSON completo en la pestaña Documentos.";
                        }),
                ]),

            // ----------------------------------------------------------------
            // Row 4 — E.firma context block.
            // Only visible when the expedient is at the EFIRMA_APPOINTMENT stage,
            // keeping the view clean for all other stages.
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
                            EfirmaAppointmentStatusEnum::SCHEDULED => 'info',
                            EfirmaAppointmentStatusEnum::ATTENDED_APPROVED => 'success',
                            EfirmaAppointmentStatusEnum::ATTENDED_REJECTED => 'danger',
                            EfirmaAppointmentStatusEnum::NO_SHOW => 'danger',
                            default => 'gray',
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
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company_display_name')
                    ->label('Empresa')
                    ->state(fn (Registration $record): string => $record->primaryLegalName?->name ?? '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('legalNames', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    )
                    ->sortable(false),

                TextColumn::make('singapur_client_code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->grow(false),

                BadgeColumn::make('stage')
                    ->label('Etapa')
                    ->formatStateUsing(fn (RegistrationStageEnum $state) => $state->shortLabel())
                    ->tooltip(fn (RegistrationStageEnum $state): string => $state->label())
                    ->colors([
                        'gray' => RegistrationStageEnum::DATA_RECEIVED->value,
                        'warning' => [
                            RegistrationStageEnum::IDENTITY_VALIDATION->value,
                            RegistrationStageEnum::ACTA_PREPARATION->value,
                        ],
                        'info' => [
                            RegistrationStageEnum::LEGAL_NAME->value,
                            RegistrationStageEnum::PARTNER_SIGNATURE->value,
                            RegistrationStageEnum::INCORPORATION->value,
                            RegistrationStageEnum::TAX_ADDRESS->value,
                            RegistrationStageEnum::SAT_REGISTRATION->value,
                            RegistrationStageEnum::EFIRMA_APPOINTMENT->value,
                        ],
                        'success' => RegistrationStageEnum::COMPLETED->value,
                    ])
                    ->grow(false),

                BadgeColumn::make('status')
                    ->label('Estatus')
                    ->formatStateUsing(fn (RegistrationStatusEnum $state) => $state->label())
                    ->colors([
                        'success' => RegistrationStatusEnum::ACTIVE->value,
                        'warning' => RegistrationStatusEnum::ON_HOLD->value,
                        'danger' => RegistrationStatusEnum::CANCELLED->value,
                        'gray' => RegistrationStatusEnum::COMPLETED->value,
                    ])
                    ->grow(false),

                TextColumn::make('notario.name')
                    ->label('Notario')
                    ->placeholder('—')
                    ->limit(18)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->grow(false),

                TextColumn::make('tasks_pending_count')
                    ->label('Tareas')
                    ->badge()
                    ->color('warning')
                    ->grow(false),

                TextColumn::make('created_at')
                    ->label('Ingreso')
                    ->date('d/m/Y')
                    ->sortable()
                    ->grow(false),
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
                ViewAction::make()->iconButton(),
                EditAction::make()->iconButton(),
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
        // Order mirrors the pipeline process:
        // 1. Documents  — first review in every stage (identity validation, acta, RFC, etc.)
        // 2. Shareholders — validate data against the KYC documents
        // 3. Legal Names  — only active work at the LEGAL_NAME stage
        // 4. Tasks        — cross-stage action items
        // 5. Notes        — cross-stage internal observations
        // 6. Stage transitions — audit trail, always last
        return [
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\ShareholdersRelationManager::class,
            RelationManagers\LegalNamesRelationManager::class,
            RelationManagers\TasksRelationManager::class,
            RelationManagers\NotesRelationManager::class,
            RelationManagers\StageTransitionsRelationManager::class,
        ];
    }

    /**
     * Return the pages registered for this resource.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        // CreateRegistration is intentionally excluded — expedients are created
        // automatically when the Singapur relay posts a webhook event.
        // Manual creation is not supported to enforce data integrity.
        return [
            'index' => Pages\ListRegistrations::route('/'),
            'view' => Pages\ViewRegistration::route('/{record}'),
            'edit' => Pages\EditRegistration::route('/{record}/edit'),
        ];
    }
}
