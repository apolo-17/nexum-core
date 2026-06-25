<?php

namespace App\Filament\Resources\MuaAccountResource\Pages;

use App\Filament\Resources\MuaAccountResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Edit page for a MUA account (soldado FIEL).
 *
 * Credential fields in the form are optional on edit — leaving them blank
 * preserves the existing stored credential without modification.
 * mutateFormDataBeforeSave() extracts them before the model is updated;
 * afterSave() persists any non-empty values to mua_credentials.
 */
class EditMuaAccount extends EditRecord
{
    /**
     * @var class-string<MuaAccountResource>
     */
    protected static string $resource = MuaAccountResource::class;

    /**
     * Credential data extracted from the form before the account row is saved.
     *
     * @var array<string, string|null>
     */
    private array $pendingCredentials = [];

    /**
     * Return the header actions for this page.
     *
     * @return list<Action>
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
     * Stores the values in $pendingCredentials so afterSave() can selectively
     * overwrite credentials that were actually provided by the user.
     *
     * @param  array<string, mixed>  $data  Form state from the Filament schema.
     * @return array<string, mixed> Data safe to pass directly to Model::fill() + save().
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingCredentials = [
            'certificate' => MuaAccountResource::uploadedFileToBase64($data['certificate_file'] ?? null),
            'private_key' => MuaAccountResource::uploadedFileToBase64($data['private_key_file'] ?? null),
            'password' => $data['private_key_password'] ?? null,
        ];

        unset($data['certificate_file'], $data['private_key_file'], $data['private_key_password']);

        return $data;
    }

    /**
     * Update the account and its FIEL credentials atomically.
     *
     * Wraps both writes in a single transaction so a credential failure rolls back
     * the account changes too. Blank credential fields are left untouched. On error
     * the exact reason is surfaced and the save is halted.
     *
     * @param  Model  $record  The account being edited.
     * @param  array<string, mixed>  $data  Account attributes (credentials already stripped).
     * @return Model The updated MuaAccount.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return DB::transaction(function () use ($record, $data): Model {
                $record = parent::handleRecordUpdate($record, $data);

                MuaAccountResource::persistCredentials($record, $this->pendingCredentials);

                return $record;
            });
        } catch (Halt $halt) {
            throw $halt;
        } catch (\Throwable $exception) {
            Log::error('MuaAccount update failed — rolled back.', [
                'mua_account_id' => $record->getKey(),
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
