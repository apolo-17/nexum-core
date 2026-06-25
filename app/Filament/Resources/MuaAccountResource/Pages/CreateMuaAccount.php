<?php

namespace App\Filament\Resources\MuaAccountResource\Pages;

use App\Filament\Resources\MuaAccountResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Create page for a new MUA account (soldado FIEL).
 *
 * Credential fields in the form are virtual — not columns on mua_accounts.
 * mutateFormDataBeforeCreate() extracts them; the account row and its credentials
 * are then written together inside a single transaction (all-or-nothing).
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
     * Create the account and its FIEL credentials atomically.
     *
     * Wraps both writes in a single transaction so a credential failure rolls back
     * the account too — never leaving a half-saved record. On error the exact
     * reason is surfaced and the save is halted.
     *
     * @param  array<string, mixed>  $data  Account attributes (credentials already stripped).
     * @return Model The created MuaAccount.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return DB::transaction(function () use ($data): Model {
                $record = parent::handleRecordCreation($data);

                MuaAccountResource::persistCredentials($record, $this->pendingCredentials);

                return $record;
            });
        } catch (Halt $halt) {
            throw $halt;
        } catch (\Throwable $exception) {
            Log::error('MuaAccount creation failed — rolled back.', [
                'error' => $exception->getMessage(),
            ]);

            Notification::make()
                ->title('No se pudo guardar la cuenta FIEL — no se creó nada.')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    /**
     * Redirect to the detail (view) page after creating the account.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
