<?php

namespace App\Filament\Resources\MuaAccountResource\Pages;

use App\Filament\Resources\MuaAccountResource;
use App\Models\MuaCredential;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

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
     * @return list<\Filament\Actions\Action>
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
     *
     * @return array<string, mixed>  Data safe to pass directly to Model::fill() + save().
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingCredentials = [
            'certificate' => $data['certificate_b64'] ?? null,
            'private_key' => $data['private_key_b64'] ?? null,
            'password'    => $data['private_key_password'] ?? null,
        ];

        unset($data['certificate_b64'], $data['private_key_b64'], $data['private_key_password']);

        return $data;
    }

    /**
     * Persist any provided FIEL credentials after the account row is saved.
     *
     * Only credentials with a non-empty value are written; blank fields leave
     * the existing stored credential unchanged (idempotent on untouched fields).
     *
     * @return void
     */
    protected function afterSave(): void
    {
        foreach ($this->pendingCredentials as $type => $value) {
            if (filled($value)) {
                MuaCredential::updateOrCreate(
                    ['mua_account_id' => $this->record->id, 'type' => $type],
                    []
                )->setEncryptedValue($value)->save();
            }
        }
    }
}
