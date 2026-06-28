<?php

namespace App\Filament\Resources\SoldadoResource\Pages;

use App\Filament\Resources\SoldadoResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Edit page for a soldado.
 *
 * FIEL credential fields are optional on edit — leaving them blank preserves the
 * existing stored credential. mutateFormDataBeforeSave() extracts them; afterSave
 * persists any non-empty values to soldado_credentials.
 */
class EditSoldado extends EditRecord
{
    /**
     * @var class-string<SoldadoResource>
     */
    protected static string $resource = SoldadoResource::class;

    /**
     * Credential data extracted from the form before the soldado row is saved.
     *
     * @var array<string, string|null>
     */
    private array $pendingCredentials = [];

    /**
     * Return the header actions for this page.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Strip FIEL credential fields from form data before updating the model.
     *
     * @param  array<string, mixed>  $data  Form state from the Filament schema.
     * @return array<string, mixed> Data safe to pass directly to Model::fill() + save().
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingCredentials = [
            'certificate' => SoldadoResource::uploadedFileToBase64($data['certificate_file'] ?? null),
            'private_key' => SoldadoResource::uploadedFileToBase64($data['private_key_file'] ?? null),
            'password' => $data['private_key_password'] ?? null,
        ];

        unset($data['certificate_file'], $data['private_key_file'], $data['private_key_password']);

        return $data;
    }

    /**
     * Update the soldado and its FIEL credentials atomically.
     *
     * @param  Model  $record  The soldado being edited.
     * @param  array<string, mixed>  $data  Soldado attributes (credentials already stripped).
     * @return Model The updated Soldado.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return DB::transaction(function () use ($record, $data): Model {
                $record = parent::handleRecordUpdate($record, $data);

                SoldadoResource::persistCredentials($record, $this->pendingCredentials);

                return $record;
            });
        } catch (Halt $halt) {
            throw $halt;
        } catch (\Throwable $exception) {
            Log::error('Soldado update failed — rolled back.', [
                'soldado_id' => $record->getKey(),
                'error' => $exception->getMessage(),
            ]);

            Notification::make()
                ->title('No se pudieron guardar los cambios.')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    /**
     * Redirect to the detail (view) page after saving changes.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
