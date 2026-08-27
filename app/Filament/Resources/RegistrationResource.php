<?php

namespace App\Filament\Resources;

use App\Enums\AppointmentTypeEnum;
use App\Enums\DocumentTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Enums\ShareholderRoleEnum;
use App\Filament\Resources\RegistrationResource\Pages;
use App\Filament\Resources\RegistrationResource\RelationManagers;
use App\Models\Appointment;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\User;
use App\Services\Denomination\ClaimPoolDenominationService;
use Illuminate\Database\Eloquent\Model;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

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
     * Restrict the full expediente resource to the notary team.
     *
     * Soldados access the panel too (their own scoped resources); they must not see
     * every company's expediente here.
     */
    public static function canAccess(): bool
    {
        // `partner` (aliado externo) entra en solo lectura: ve expedientes y descarga
        // documentos (menos e.firma), pero no puede crear/editar/borrar nada.
        return Auth::user()?->hasAnyRole(['super_admin', 'notario', 'asistente_notario', 'partner']) ?? false;
    }

    /**
     * A partner is read-only: block create/edit/delete entirely for that role.
     */
    public static function canCreate(): bool
    {
        return ! (Auth::user()?->isPartner() ?? false);
    }

    public static function canEdit(Model $record): bool
    {
        return ! (Auth::user()?->isPartner() ?? false);
    }

    public static function canDelete(Model $record): bool
    {
        return ! (Auth::user()?->isPartner() ?? false);
    }

    public static function canDeleteAny(): bool
    {
        return ! (Auth::user()?->isPartner() ?? false);
    }

    /**
     * The registration's e.firma (FIEL) appointment — the source of truth for the e.firma
     * cita's status and date. The old efirma_status flow is not used.
     */
    private static function fielAppointment(Registration $registration): ?Appointment
    {
        return $registration->appointments()
            ->where('type', AppointmentTypeEnum::FIEL->value)
            ->latest()
            ->first();
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
                        ->maxLength(255)
                        ->helperText('Identificador único del expediente. Si se dio de alta fuera del '
                            .'relay, inventa uno trazable (por ejemplo EXT-0001).'),

                    TextInput::make('singapur_folder_name')
                        ->label('Carpeta del relay')
                        ->maxLength(255)
                        ->placeholder('000003_NOVA CONSULTORA EMPRESARIAL')
                        ->helperText('Sólo si el expediente vino de China. Déjalo vacío en altas manuales.'),

                    TextInput::make('singapur_package_id')
                        ->label('ID de paquete ZIP')
                        ->maxLength(255),
                ]),

            Section::make('Empresa')
                ->columns(2)
                ->schema([
                    Select::make('pool_legal_name_id')
                        ->label('Denominación aprobada (pool)')
                        ->columnSpanFull()
                        ->searchable()
                        ->native(false)
                        ->placeholder('Sin vincular — captura la razón social a mano')
                        ->options(fn (?Registration $record): array => self::poolDenominationOptions($record))
                        ->helperText('Toma una denominación ya autorizada por la SE y la vuelve la razón social '
                            .'del expediente. Su constancia se adjunta automáticamente en Documentos.')
                        // Virtual field: claiming happens in legal_names + documents.
                        ->dehydrated(false)
                        ->live()
                        ->afterStateHydrated(
                            fn (Select $component, ?Registration $record): mixed => $component->state(
                                self::linkedPoolDenominationId($record)
                            )
                        )
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $denomination = filled($state) ? LegalName::find($state) : null;

                            if ($denomination === null) {
                                return;
                            }

                            // Mirror the claimed name into the (now read-only) fields so the
                            // operator sees exactly what will be saved.
                            $set('legal_name', $denomination->name);
                            $set('legal_name_status', LegalNameStatusEnum::APPROVED->value);
                        })
                        ->saveRelationshipsUsing(
                            fn (Registration $record, ?string $state): mixed => self::claimPoolDenomination(
                                $record,
                                $state,
                            )
                        ),

                    TextInput::make('legal_name')
                        ->label('Razón social')
                        ->required(fn (Get $get): bool => blank($get('pool_legal_name_id')))
                        // A name authorized by the SE is not editable by hand.
                        ->readOnly(fn (Get $get): bool => filled($get('pool_legal_name_id')))
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->helperText('Se guarda como la denominación de prioridad 1 del expediente.')
                        // Virtual field: it lives in legal_names, not in registrations.
                        ->dehydrated(false)
                        ->afterStateHydrated(
                            fn (TextInput $component, ?Registration $record): mixed => $component->state(
                                $record?->primaryLegalName?->name
                            )
                        )
                        ->saveRelationshipsUsing(
                            fn (Registration $record, ?string $state, Get $get): mixed => self::syncPrimaryLegalName(
                                $record,
                                $state,
                                $get('legal_name_status'),
                                $get('pool_legal_name_id'),
                            )
                        ),

                    Select::make('legal_name_status')
                        ->label('Estatus de la denominación')
                        ->options(
                            collect(LegalNameStatusEnum::cases())
                                ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                        )
                        ->default(LegalNameStatusEnum::APPROVED->value)
                        ->disabled(fn (Get $get): bool => filled($get('pool_legal_name_id')))
                        ->dehydrated(false)
                        ->afterStateHydrated(
                            fn (Select $component, ?Registration $record): mixed => $component->state(
                                $record?->primaryLegalName?->status?->value
                                    ?? LegalNameStatusEnum::APPROVED->value
                            )
                        )
                        ->helperText('Un expediente ya constituido llega con la denominación aprobada.'),

                    Select::make('company_type')
                        ->label('Tipo de sociedad')
                        ->options([
                            'SA de CV' => 'SA de CV',
                            'SRL de CV' => 'SRL de CV',
                            'SAPI de CV' => 'SAPI de CV',
                        ]),

                    Textarea::make('company_object')
                        ->label('Objeto social')
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText('Necesario para generar el acta constitutiva.'),

                    TextInput::make('capital_social')
                        ->label('Capital social')
                        ->numeric()
                        ->prefix('$')
                        ->suffix('MXN')
                        ->helperText('Mínimo legal para SA de CV: 50,000.'),

                    TextInput::make('rfc')
                        ->label('RFC')
                        ->maxLength(13),
                ]),

            Section::make('Socios')
                ->description('Captura aquí a los accionistas del expediente. Los datos que faltan para '
                    .'el acta (género, estado civil, nacimiento, domicilio) se completan después en la '
                    .'pestaña Socios del expediente.')
                ->visibleOn('create')
                ->schema([
                    Repeater::make('shareholders')
                        ->relationship()
                        ->hiddenLabel()
                        ->columns(2)
                        ->defaultItems(1)
                        ->addActionLabel('Agregar socio')
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->schema([
                            TextInput::make('name')
                                ->label('Nombre completo')
                                ->required()
                                ->columnSpanFull(),

                            TextInput::make('nationality')
                                ->label('Nacionalidad')
                                ->required()
                                ->default('China'),

                            TextInput::make('passport_number')
                                ->label('N.° pasaporte'),

                            TextInput::make('participation_percentage')
                                ->label('% de participación')
                                ->numeric()
                                ->suffix('%')
                                ->required(),

                            Select::make('role')
                                ->label('Rol')
                                ->options(
                                    collect(ShareholderRoleEnum::cases())
                                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                                )
                                ->default(ShareholderRoleEnum::SHAREHOLDER->value)
                                ->required(),

                            TextInput::make('email')
                                ->label('Correo')
                                ->email(),

                            TextInput::make('phone')
                                ->label('Teléfono'),

                            Toggle::make('is_married')
                                ->label('Casado/a')
                                ->helperText('Soltero → 2 docs KYC, casado → 4 (acta de matrimonio + pasaporte del cónyuge).')
                                ->columnSpanFull(),
                        ]),
                ]),

            Section::make('Domicilio fiscal')
                ->description('Captúralo del comprobante (recibo de luz, internet, contrato de arrendamiento…). '
                    .'Sube el PDF en Documentos como «Comprobante de domicilio fiscal (México)».')
                ->columns(3)
                ->collapsed()
                ->schema([
                    TextInput::make('fiscal_street')->label('Calle')->maxLength(255)->columnSpan(2),
                    TextInput::make('fiscal_ext_number')->label('Núm. exterior')->maxLength(50),
                    TextInput::make('fiscal_int_number')->label('Núm. interior')->maxLength(50),
                    TextInput::make('fiscal_neighborhood')->label('Colonia')->maxLength(255),
                    TextInput::make('fiscal_postal_code')->label('Código postal')->maxLength(10),
                    TextInput::make('fiscal_municipality')->label('Alcaldía / municipio')->maxLength(255),
                    TextInput::make('fiscal_state')->label('Estado')->maxLength(255)->default('Ciudad de México'),
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
                        ->default(RegistrationStageEnum::DATA_RECEIVED->value)
                        ->required(),

                    Select::make('status')
                        ->label('Estatus')
                        ->options(
                            collect(RegistrationStatusEnum::cases())
                                ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                        )
                        ->default(RegistrationStatusEnum::ACTIVE->value)
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
     * Persist the company name typed in the form as the priority-1 denomination.
     *
     * The razón social lives in legal_names, not in registrations, so the form field is
     * virtual and this runs once the registration exists. Updating (rather than adding)
     * keeps a single priority-1 row, which is the one every downstream consumer reads —
     * the dashboard title, the acta, and the SAT bot payload.
     *
     * @param  Registration  $record  The registration just created or saved.
     * @param  string|null  $name  The company name typed by the user.
     * @param  string|null  $status  LegalNameStatusEnum value chosen alongside the name.
     * @param  string|null  $poolLegalNameId  Pool denomination selected in the same form, if any.
     */
    protected static function syncPrimaryLegalName(
        Registration $record,
        ?string $name,
        ?string $status,
        ?string $poolLegalNameId = null,
    ): void {
        // A pool denomination owns priority 1: claimPoolDenomination() writes it and
        // its SE-authorized name/status must never be overwritten from the text field.
        if (filled($poolLegalNameId)) {
            return;
        }

        if (blank($name)) {
            return;
        }

        $current = $record->primaryLegalName;

        // A denomination authorized by the SE carries a folio: the name on it is the
        // one on the constancia, so it cannot be rewritten by hand from this form
        // (clearing the picker must not become a back door to renaming it).
        if ($current !== null
            && filled($current->clave_unica_denominacion)
            && $current->name !== $name) {
            Notification::make()
                ->title('La razón social no se cambió.')
                ->body("«{$current->name}» está autorizada por la SE (folio {$current->clave_unica_denominacion}). "
                    .'Para usar otra, vincula una denominación distinta del pool.')
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $record->legalNames()->updateOrCreate(
            ['priority' => 1],
            [
                'name' => $name,
                'status' => LegalNameStatusEnum::tryFrom((string) $status) ?? LegalNameStatusEnum::APPROVED,
            ],
        );
    }

    /**
     * Build the options for the pool denomination picker.
     *
     * Lists every SE-approved name still free to claim, plus the one already linked
     * to this expedient (otherwise the select would render empty on an edit form and
     * look as if the link had been lost).
     *
     * @param  Registration|null  $record  The expedient being edited, null on create.
     * @return array<string, string> Denomination id => label.
     */
    protected static function poolDenominationOptions(?Registration $record): array
    {
        $options = LegalName::query()
            ->whereNull('registration_id')
            ->where('status', LegalNameStatusEnum::APPROVED->value)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (LegalName $name): array => [
                $name->id => self::poolDenominationLabel($name),
            ])
            ->all();

        $linked = $record?->primaryLegalName;

        if ($linked !== null && filled($linked->clave_unica_denominacion)) {
            $options[$linked->id] = self::poolDenominationLabel($linked).' — vinculada';
        }

        return $options;
    }

    /**
     * Format a denomination for the picker: name, régimen and SE folio.
     *
     * @param  LegalName  $name  The denomination to label.
     */
    protected static function poolDenominationLabel(LegalName $name): string
    {
        $parts = array_filter([
            $name->name,
            filled($name->company_type) ? strtoupper((string) $name->company_type) : null,
            filled($name->clave_unica_denominacion) ? "folio {$name->clave_unica_denominacion}" : null,
        ]);

        return implode(' · ', $parts);
    }

    /**
     * Resolve which pool denomination is already linked to this expedient, if any.
     *
     * Only a name carrying an SE folio counts as coming from the pool; a manually
     * typed razón social must leave the picker empty so it stays editable.
     *
     * @param  Registration|null  $record  The expedient being edited, null on create.
     * @return string|null The linked denomination id.
     */
    protected static function linkedPoolDenominationId(?Registration $record): ?string
    {
        $linked = $record?->primaryLegalName;

        return $linked !== null && filled($linked->clave_unica_denominacion)
            ? $linked->id
            : null;
    }

    /**
     * Claim the pool denomination chosen in the form for this expedient.
     *
     * Runs on save (the picker is a virtual field). Skips silently when nothing is
     * selected or when the very same name is already linked — re-saving the form
     * must not re-run the claim.
     *
     * @param  Registration  $record  The registration just created or saved.
     * @param  string|null  $legalNameId  The pool denomination id selected in the form.
     */
    protected static function claimPoolDenomination(Registration $record, ?string $legalNameId): void
    {
        if (blank($legalNameId) || $legalNameId === self::linkedPoolDenominationId($record)) {
            return;
        }

        $denomination = LegalName::find($legalNameId);

        if ($denomination === null) {
            Notification::make()
                ->title('La denominación seleccionada ya no existe.')
                ->danger()
                ->send();

            return;
        }

        $result = app(ClaimPoolDenominationService::class)->claim($denomination, $record);

        if (! $result->claimed) {
            Notification::make()
                ->title("«{$denomination->name}»: no se pudo vincular.")
                ->body($result->reason)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if (! $result->constanciaAttached) {
            Notification::make()
                ->title("«{$denomination->name}»: vinculada sin constancia.")
                ->body($result->reason)
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title("«{$denomination->name}»: vinculada al expediente.")
            ->body('Se adjuntó la constancia de la SE en Documentos.')
            ->success()
            ->send();
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
            Section::make('Domicilio fiscal')
                ->columnSpan(3)
                ->columns(3)
                ->schema([
                    TextEntry::make('fiscal_address')
                        ->label('Dirección')
                        ->columnSpan(2)
                        ->placeholder('Sin capturar — edita el expediente para agregarlo')
                        ->state(fn (Registration $record): ?string => $record->fiscalAddress()),

                    TextEntry::make('fiscal_proof')
                        ->label('Comprobante')
                        ->state(function (Registration $record): string {
                            $doc = $record->documents()
                                ->where('type', DocumentTypeEnum::PROOF_OF_ADDRESS_MX->value)
                                ->latest()
                                ->first();

                            return $doc ? 'Cargado' : 'Sin comprobante';
                        })
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Cargado' ? 'success' : 'gray'),
                ]),

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
            // Row 4 — DocuSign / Firma electrónica status block.
            // Visible from PARTNER_SIGNATURE stage onwards so the notary can
            // monitor signing progress without leaving the page.
            // ----------------------------------------------------------------
            Section::make('Firma electrónica (DocuSign)')
                ->columnSpan(3)
                ->columns(3)
                ->visible(fn (Registration $record): bool => in_array(
                    $record->stage,
                    [
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
                    TextEntry::make('docusign_envelope_status')
                        ->label('Estado del envelope')
                        ->columnSpan(1)
                        ->state(function (Registration $record): string {
                            $actaFinal = $record->documents()
                                ->where('type', DocumentTypeEnum::ACTA_FINAL->value)
                                ->latest()
                                ->first();

                            if ($actaFinal === null) {
                                return '⚠️ Sin ACTA_FINAL — genera el .docx primero';
                            }

                            $signStatus = $actaFinal->template_data['sign_status'] ?? null;

                            if ($signStatus === null) {
                                return '⏳ No enviado — usa el botón "✍ Enviar a firma" en el header';
                            }

                            $status = $signStatus['status'] ?? '—';
                            $envelopeId = $signStatus['envelope_id'] ?? '—';
                            $sentAt = $signStatus['sent_at'] ?? null;
                            $completedAt = $signStatus['completed_at'] ?? null;
                            $voidedAt = $signStatus['voided_at'] ?? null;

                            $icon = match ($status) {
                                'sent' => '📤',
                                'completed' => '✅',
                                'voided' => '🚫',
                                default => '⏳',
                            };

                            $line = "{$icon} {$status}";

                            if ($sentAt !== null) {
                                $line .= ' — enviado: '.Carbon::parse($sentAt)->format('d/m/Y H:i');
                            }

                            if ($completedAt !== null) {
                                $line .= ' / firmado: '.Carbon::parse($completedAt)->format('d/m/Y H:i');
                            }

                            if ($voidedAt !== null) {
                                $line .= ' / anulado: '.Carbon::parse($voidedAt)->format('d/m/Y H:i');
                                $line .= ' — '.($signStatus['void_reason'] ?? '');
                            }

                            $line .= "\nEnvelope: {$envelopeId}";

                            return $line;
                        }),

                    TextEntry::make('docusign_signer_status')
                        ->label('Estado por accionista')
                        ->columnSpan(2)
                        ->state(function (Registration $record): string {
                            $actaFinal = $record->documents()
                                ->where('type', DocumentTypeEnum::ACTA_FINAL->value)
                                ->latest()
                                ->first();

                            $signerStatus = $actaFinal?->template_data['sign_status']['signer_status'] ?? null;

                            if ($signerStatus === null) {
                                return '—';
                            }

                            $lines = [];

                            foreach ($signerStatus as $key => $info) {
                                $icon = $info['status'] === 'completed' ? '✅' : '⏳';
                                $nombre = $info['nombre'] ?? $key;
                                $email = $info['email'] ?? '';
                                $signedAt = isset($info['signed_at'])
                                    ? ' — firmado: '.Carbon::parse($info['signed_at'])->format('d/m/Y H:i')
                                    : '';

                                $lines[] = "{$icon} {$nombre} ({$email}){$signedAt}";
                            }

                            return implode("\n", $lines);
                        }),
                ]),

            // ----------------------------------------------------------------
            // Row 5 — E.firma context block.
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
                    // Estado/fecha de la cita REAL de e.firma (la cita FIEL), no el flujo viejo.
                    TextEntry::make('efirma_cita_estado')
                        ->label('Estado de la cita')
                        ->badge()
                        ->state(fn (Registration $record): string => self::fielAppointment($record)?->status?->label() ?? 'Sin cita')
                        ->color(fn (Registration $record): string => self::fielAppointment($record)?->status?->color() ?? 'gray'),

                    TextEntry::make('efirma_cita_fecha')
                        ->label('Fecha de cita')
                        ->state(fn (Registration $record): ?string => self::fielAppointment($record)?->scheduled_at?->format('d/m/Y H:i'))
                        ->placeholder('Sin confirmar'),

                    // Archivos de la e.firma: se guardan en company_fiel_* (donde sube el soldado/admin).
                    IconEntry::make('key_subido')
                        ->label('.key subido')
                        ->boolean()
                        ->trueIcon('heroicon-o-check-circle')
                        ->falseIcon('heroicon-o-x-circle')
                        ->state(fn (Registration $record): bool => filled($record->company_fiel_key_path)),

                    IconEntry::make('cer_subido')
                        ->label('.cer subido')
                        ->boolean()
                        ->trueIcon('heroicon-o-check-circle')
                        ->falseIcon('heroicon-o-x-circle')
                        ->state(fn (Registration $record): bool => filled($record->company_fiel_cer_path)),
                ]),

            // ----------------------------------------------------------------
            // Row 6 — Safeguarded company credentials (e.firma + RFC).
            // Independent of the e.firma appointment flow: these are stored for
            // retrieval/download only. Always visible so the team can upload and
            // download them at any stage. Files are served via a gated route;
            // the e.firma password is revealed only to super_admin.
            // ----------------------------------------------------------------
            Section::make('Credenciales de la empresa (FIEL + RFC)')
                ->description('Resguardo del .cer, .key, contraseña y RFC de la empresa para descarga segura.')
                // Nunca visible para el rol partner (solo lectura, sin acceso a la e.firma).
                ->visible(fn (): bool => ! (auth()->user()?->isPartner() ?? false))
                ->columnSpan(3)
                ->columns(4)
                ->schema([
                    TextEntry::make('company_fiel_cer_path')
                        ->label('Certificado .cer')
                        ->badge()
                        ->state(fn (Registration $record): string => filled($record->company_fiel_cer_path) ? 'Descargar' : 'No cargado')
                        ->color(fn (Registration $record): string => filled($record->company_fiel_cer_path) ? 'success' : 'gray')
                        ->url(fn (Registration $record): ?string => filled($record->company_fiel_cer_path)
                            ? route('admin.company-credentials.download', ['registration' => $record, 'type' => 'cer'])
                            : null)
                        ->openUrlInNewTab(),

                    TextEntry::make('company_fiel_key_path')
                        ->label('Llave .key')
                        ->badge()
                        ->state(fn (Registration $record): string => filled($record->company_fiel_key_path) ? 'Descargar' : 'No cargado')
                        ->color(fn (Registration $record): string => filled($record->company_fiel_key_path) ? 'success' : 'gray')
                        ->url(fn (Registration $record): ?string => filled($record->company_fiel_key_path)
                            ? route('admin.company-credentials.download', ['registration' => $record, 'type' => 'key'])
                            : null)
                        ->openUrlInNewTab(),

                    TextEntry::make('company_rfc_path')
                        ->label('RFC / Constancia')
                        ->badge()
                        ->state(fn (Registration $record): string => filled($record->company_rfc_path) ? 'Descargar' : 'No cargado')
                        ->color(fn (Registration $record): string => filled($record->company_rfc_path) ? 'success' : 'gray')
                        ->url(fn (Registration $record): ?string => filled($record->company_rfc_path)
                            ? route('admin.company-credentials.download', ['registration' => $record, 'type' => 'rfc'])
                            : null)
                        ->openUrlInNewTab(),

                    TextEntry::make('company_fiel_req_path')
                        ->label('Requerimiento .req')
                        ->badge()
                        ->state(fn (Registration $record): string => filled($record->company_fiel_req_path) ? 'Descargar' : 'No cargado')
                        ->color(fn (Registration $record): string => filled($record->company_fiel_req_path) ? 'success' : 'gray')
                        ->url(fn (Registration $record): ?string => filled($record->company_fiel_req_path)
                            ? route('admin.company-credentials.download', ['registration' => $record, 'type' => 'req'])
                            : null)
                        ->openUrlInNewTab(),

                    // NUNCA mostrar la contraseña en texto plano. Se guarda cifrada y solo se
                    // indica si está registrada o no; el valor no se expone en la vista.
                    TextEntry::make('company_fiel_password')
                        ->label('Contraseña FIEL')
                        ->badge()
                        ->state(fn (Registration $record): string => filled($record->company_fiel_password) ? '✓ Registrada' : 'Sin registrar')
                        ->color(fn (Registration $record): string => filled($record->company_fiel_password) ? 'success' : 'gray'),
                ]),

            Section::make('Entregables a China')
                ->description('Los 5 documentos que China necesita y su estado de entrega.')
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    \Filament\Infolists\Components\ViewEntry::make('china_deliverables')
                        ->hiddenLabel()
                        ->view('filament.infolists.china-deliverables'),
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

                TextColumn::make('china_deliverables')
                    ->label('China')
                    ->badge()
                    ->state(function (Registration $record): string {
                        $svc = app(\App\Services\Singapur\ChinaDeliverablesService::class);

                        return $svc->deliveredCount($record).'/'.$svc->total();
                    })
                    ->color(function (Registration $record): string {
                        $svc = app(\App\Services\Singapur\ChinaDeliverablesService::class);
                        $done = $svc->deliveredCount($record);

                        return match (true) {
                            $done === $svc->total() => 'success',
                            $done === 0 => 'gray',
                            default => 'warning',
                        };
                    })
                    ->tooltip('Entregables confirmados por China (acta, RPP, domicilio, CSF, e.firma).')
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
        // El partner (solo lectura) únicamente ve la pestaña de Documentos para descargar
        // archivos; el resto de pestañas (socios, citas, tareas, notas, etc.) son internas.
        if (Auth::user()?->isPartner() ?? false) {
            return [
                RelationManagers\DocumentsRelationManager::class,
            ];
        }

        return [
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\ShareholdersRelationManager::class,
            RelationManagers\SoldadosRelationManager::class,
            RelationManagers\AppointmentsRelationManager::class,
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
        // Expedients normally arrive from the Singapur relay webhook. CreateRegistration
        // covers the other case: a company incorporated outside the platform that the
        // team needs to register manually to keep working it here.
        return [
            'index' => Pages\ListRegistrations::route('/'),
            'create' => Pages\CreateRegistration::route('/create'),
            'view' => Pages\ViewRegistration::route('/{record}'),
            'edit' => Pages\EditRegistration::route('/{record}/edit'),
            'edit-acta-inline' => Pages\EditActaInlinePage::route('/{record}/edit-acta-inline'),
        ];
    }
}
