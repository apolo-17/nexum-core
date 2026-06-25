<?php

namespace App\Filament\Resources\MuaAccountResource\Pages;

use App\Filament\Resources\MuaAccountResource;
use App\Models\MuaCredential;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page for a new MUA account (soldado FIEL).
 *
 * Credential fields in the form are virtual — not columns on mua_accounts.
 * mutateFormDataBeforeCreate() extracts them before model creation and stores
 * them temporarily; afterCreate() persists them to mua_credentials.
 */
class CreateMuaAccount extends CreateRecord
{
    /**
     * @var class-string<MuaAccountResource>
     */
    protected static string $resource = MuaAccountResource::class;

    /**
     * Credential data extracted from the form before the account row is created.
     *
     * @var array<string, string|null>
     */
    private array $pendingCredentials = [];

    /**
     * Strip FIEL credential fields from form data before creating the model.
     *
     * Stores the values in $pendingCredentials so afterCreate() can persist
     * them to the mua_credentials table once the parent row exists.
     *
     * @param  array<string, mixed>  $data  Form state from the Filament schema.
     * @return array<string, mixed> Data safe to pass directly to Model::create().
     */
    protected function mutateFormDataBeforeCreate(array $data): array
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
     * Persist FIEL credentials to mua_credentials after the account row is created.
     *
     * Only non-empty values are written; all three are expected on create since
     * the form marks them as required for the 'create' operation.
     */
    protected function afterCreate(): void
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
