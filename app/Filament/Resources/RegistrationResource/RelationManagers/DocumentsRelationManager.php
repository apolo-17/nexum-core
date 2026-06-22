<?php

namespace App\Filament\Resources\RegistrationResource\RelationManagers;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Manages documents for a registration expedient.
 *
 * Documents come from two sources:
 * - Relay KYC files: received via webhook at DATA_RECEIVED stage, stored in R2/MinIO.
 * - Manual uploads: added by the notary team through this relation manager.
 *
 * The notary team evaluates each document (approve / reject / pending) directly
 * from the inline preview modal without leaving the expedition view.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documentos';

    /**
     * Allow mutations (create, edit, delete) even when rendered inside a ViewRecord page.
     *
     * Filament marks any relation manager as read-only when the parent page extends ViewRecord.
     * The notary team must be able to upload and evaluate documents from the expedition view,
     * so we explicitly opt out of the read-only restriction here.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Define the form schema for manually uploading documents to R2 / MinIO.
     *
     * This form is used by the "Agregar documento" button. It includes document type,
     * file upload, and an initial evaluation state (default: approved, since the
     * notary has already reviewed the document before uploading it manually).
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Tipo de documento')
                ->options(
                    collect(DocumentTypeEnum::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                )
                ->required()
                ->columnSpanFull(),

            FileUpload::make('storage_path')
                ->label('Archivo')
                ->disk(config('filesystems.default'))
                ->directory(fn () => 'documents/'.$this->ownerRecord->id.'/manual')
                ->storeFileNamesIn('name')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                ->maxSize(20480)
                ->required()
                ->columnSpanFull(),

            Radio::make('evaluation')
                ->label('Evaluación del documento')
                ->options([
                    'approved' => '✓ Aprobar',
                    'rejected' => '✗ Rechazar',
                    'pending' => '— Pendiente de revisión',
                ])
                ->default('approved')
                ->required()
                ->columnSpanFull(),

            Textarea::make('rejection_reason')
                ->label('Motivo de rechazo')
                ->rows(2)
                ->nullable()
                ->visible(fn ($get) => $get('evaluation') === 'rejected')
                ->columnSpanFull(),
        ]);
    }

    /**
     * Define the table columns and actions for the documents list.
     */
    public function table(Table $table): Table
    {
        return $table
            ->description(function (): ?string {
                $registration = $this->ownerRecord->load('shareholders', 'documents');
                $missing = $registration->missingKycDocuments();

                if (empty($missing)) {
                    return null;
                }

                $lines = [];

                foreach ($missing as $typeValue => $count) {
                    $label = DocumentTypeEnum::from($typeValue)->label();
                    $lines[] = "{$count}× {$label}";
                }

                return '⚠️ Documentos KYC faltantes: '.implode(', ', $lines).'.';
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Documento')
                    ->searchable()
                    ->limit(45)
                    ->tooltip(fn (Document $record): string => $record->name)
                    ->grow(true),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (DocumentTypeEnum $state) => $state->label())
                    ->grow(false),

                BadgeColumn::make('evaluation_status')
                    ->label('Estado')
                    ->state(fn (Document $record): string => $record->evaluationStatus())
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',
                        default => 'Pendiente',
                    })
                    ->grow(false),

                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->grow(false),
            ])

            // ---------------------------------------------------------------
            // Row actions — icon-only to keep the table compact.
            // ---------------------------------------------------------------
            ->actions([
                // Preview modal — opens file inline with evaluation controls.
                Action::make('previewDocument')
                    ->label('Vista previa')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->iconButton()
                    ->visible(fn (Document $record): bool => filled($record->storage_path))
                    ->modalHeading(
                        fn (Document $record): string => $record->type->label().' — '.$record->name
                    )
                    ->modalWidth('7xl')
                    ->modalSubmitActionLabel('Guardar evaluación')
                    // Hide the submit button entirely once the document is evaluated:
                    // its status is final and the modal becomes view-only.
                    ->modalSubmitAction(fn (Document $record) => $record->isEvaluated() ? false : null)
                    ->form([
                        // Evaluation radio — pre-filled with the current state.
                        // Disabled once evaluated: status is final and cannot change.
                        Radio::make('evaluation')
                            ->label('Evaluación')
                            ->options([
                                'approved' => '✓ Aprobar',
                                'rejected' => '✗ Rechazar',
                                'pending' => '— Pendiente de revisión',
                            ])
                            ->required()
                            ->disabled(fn (Document $record): bool => $record->isEvaluated())
                            ->helperText(fn (Document $record): ?string => $record->isEvaluated()
                                ? 'Este documento ya fue evaluado; su estado es final y no se puede cambiar.'
                                : null
                            )
                            ->default(fn (Document $record): string => $record->evaluationStatus()),

                        Textarea::make('rejection_reason')
                            ->label('Motivo de rechazo')
                            ->rows(2)
                            ->nullable()
                            ->disabled(fn (Document $record): bool => $record->isEvaluated())
                            ->visible(fn ($get) => $get('evaluation') === 'rejected'),
                    ])
                    ->fillForm(fn (Document $record): array => [
                        'evaluation' => $record->evaluationStatus(),
                        'rejection_reason' => $record->rejection_reason,
                    ])
                    ->modalContent(fn (Document $record) => view(
                        'filament.documents.preview-iframe',
                        [
                            'previewUrl' => route('admin.documents.preview', $record),
                            'isImage' => $record->isImage(),
                            'isPdf' => $record->isPdf(),
                        ]
                    ))
                    ->action(function (Document $record, array $data): void {
                        // Final status guard: an already-evaluated document cannot change.
                        if ($record->isEvaluated()) {
                            Notification::make()
                                ->title('No se aplicaron cambios')
                                ->body('Este documento ya fue evaluado; su estado es final y no se puede modificar.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $userId = Auth::id();
                        $now = now();

                        $update = ['rejection_reason' => null];

                        if ($data['evaluation'] === 'approved') {
                            $update['verified_at'] = $now;
                            $update['verified_by'] = $userId;
                            $update['rejected_at'] = null;
                            $update['rejected_by'] = null;
                        } elseif ($data['evaluation'] === 'rejected') {
                            $update['rejected_at'] = $now;
                            $update['rejected_by'] = $userId;
                            $update['rejection_reason'] = $data['rejection_reason'] ?? null;
                            $update['verified_at'] = null;
                            $update['verified_by'] = null;
                        } else {
                            // pending — clear both timestamps
                            $update['verified_at'] = null;
                            $update['verified_by'] = null;
                            $update['rejected_at'] = null;
                            $update['rejected_by'] = null;
                        }

                        $record->update($update);
                    }),

                // Download — force browser download.
                Action::make('downloadDocument')
                    ->label('Descargar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->iconButton()
                    ->url(
                        fn (Document $record): string => route(
                            'admin.documents.relay-download',
                            $record
                        )
                    )
                    ->openUrlInNewTab()
                    ->visible(fn (Document $record): bool => filled($record->storage_path)),

                // Delete — with confirmation.
                DeleteAction::make()
                    ->iconButton(),
            ])

            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(
                        collect(DocumentTypeEnum::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                    ),
            ])

            ->headerActions([
                CreateAction::make()
                    ->label('Agregar documento')
                    ->mutateFormDataUsing(function (array $data): array {
                        $userId = Auth::id();
                        $now = now();

                        $data['uploaded_by'] = $userId;
                        $data['stage'] = $this->ownerRecord->stage;

                        // Apply the initial evaluation chosen in the create form.
                        if (($data['evaluation'] ?? 'pending') === 'approved') {
                            $data['verified_at'] = $now;
                            $data['verified_by'] = $userId;
                        } elseif (($data['evaluation'] ?? 'pending') === 'rejected') {
                            $data['rejected_at'] = $now;
                            $data['rejected_by'] = $userId;
                        }

                        // Remove the virtual field — not a real DB column.
                        unset($data['evaluation']);

                        return $data;
                    }),
            ])

            // ---------------------------------------------------------------
            // Bulk actions — row checkboxes and "select all" appear automatically.
            // Let the notary team approve or reject many documents at once.
            // ---------------------------------------------------------------
            ->bulkActions([
                BulkActionGroup::make([
                    // Approve all selected documents.
                    BulkAction::make('approveSelected')
                        ->label('Aprobar seleccionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar documentos seleccionados')
                        ->modalDescription('Los documentos seleccionados se marcarán como aprobados.')
                        ->action(function (Collection $records): void {
                            $userId = Auth::id();
                            $now = now();

                            // Only pending documents can be evaluated; already
                            // approved/rejected ones keep their final status.
                            [$pending, $locked] = $records->partition(
                                fn (Document $record): bool => ! $record->isEvaluated()
                            );

                            foreach ($pending as $record) {
                                $record->update([
                                    'verified_at' => $now,
                                    'verified_by' => $userId,
                                    'rejected_at' => null,
                                    'rejected_by' => null,
                                    'rejection_reason' => null,
                                ]);
                            }

                            self::notifyBulkResult($pending->count(), $locked->count(), 'aprobado');
                        })
                        ->deselectRecordsAfterCompletion(),

                    // Reject all selected documents, with an optional shared reason.
                    BulkAction::make('rejectSelected')
                        ->label('Rechazar seleccionados')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->modalHeading('Rechazar documentos seleccionados')
                        ->modalSubmitActionLabel('Rechazar')
                        ->schema([
                            Textarea::make('rejection_reason')
                                ->label('Motivo de rechazo (opcional, se aplica a todos)')
                                ->rows(2)
                                ->nullable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $userId = Auth::id();
                            $now = now();
                            $reason = $data['rejection_reason'] ?? null;

                            // Only pending documents can be evaluated; already
                            // approved/rejected ones keep their final status.
                            [$pending, $locked] = $records->partition(
                                fn (Document $record): bool => ! $record->isEvaluated()
                            );

                            foreach ($pending as $record) {
                                $record->update([
                                    'rejected_at' => $now,
                                    'rejected_by' => $userId,
                                    'rejection_reason' => $reason,
                                    'verified_at' => null,
                                    'verified_by' => null,
                                ]);
                            }

                            self::notifyBulkResult($pending->count(), $locked->count(), 'rechazado');
                        })
                        ->deselectRecordsAfterCompletion(),

                    // Delete — kept inside the same group for convenience.
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Emit a notification summarizing a bulk evaluation, warning about locked documents.
     *
     * Documents already approved or rejected cannot change status, so they are
     * skipped even when selected. This surfaces how many were updated and how many
     * were left untouched because they were already evaluated.
     *
     * @param  int  $changed  Number of documents whose status was updated.
     * @param  int  $locked  Number of selected documents skipped (already evaluated).
     * @param  string  $verb  Past participle for the action, e.g. 'aprobado' | 'rechazado'.
     */
    private static function notifyBulkResult(int $changed, int $locked, string $verb): void
    {
        // Nothing changed: every selected document was already evaluated.
        if ($changed === 0) {
            Notification::make()
                ->title('No se aplicaron cambios')
                ->body("Los {$locked} documento(s) seleccionado(s) ya tienen un estado final y no se pueden modificar.")
                ->warning()
                ->send();

            return;
        }

        $notification = Notification::make()
            ->title("{$changed} documento(s) {$verb}(s)");

        if ($locked > 0) {
            // Some were updated, but others were skipped because they were locked.
            $notification
                ->body("{$locked} documento(s) ya estaban evaluados y no se modificaron.")
                ->warning();
        } else {
            $notification->success();
        }

        $notification->send();
    }
}
