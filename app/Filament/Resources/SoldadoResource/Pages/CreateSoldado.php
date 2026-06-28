<?php

namespace App\Filament\Resources\SoldadoResource\Pages;

use App\Filament\Resources\SoldadoResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Create page for a new soldado.
 *
 * FIEL credential fields in the form are virtual — not columns on soldados.
 * mutateFormDataBeforeCreate() extracts them; the soldado row and its credentials
 * are then written together inside a single transaction (all-or-nothing).
 */
class CreateSoldado extends CreateRecord
{
    /**
     * @var class-string<SoldadoResource>
     */
    protected static string $resource = SoldadoResource::class;

    /**
     * Credential data extracted from the form before the soldado row is created.
     *
     * @var array<string, string|null>
     */
    private array $pendingCredentials = [];

    /**
     * Strip FIEL credential fields from form data before creating the model.
     *
     * @param  array<string, mixed>  $data  Form state from the Filament schema.
     * @return array<string, mixed> Data safe to pass directly to Model::create().
     */
    protected function mutateFormDataBeforeCreate(array $data): array
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
     * Create the soldado and its FIEL credentials atomically.
     *
     * @param  array<string, mixed>  $data  Soldado attributes (credentials already stripped).
     * @return Model The created Soldado.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return DB::transaction(function () use ($data): Model {
                $record = parent::handleRecordCreation($data);

                SoldadoResource::persistCredentials($record, $this->pendingCredentials);

                return $record;
            });
        } catch (Halt $halt) {
            throw $halt;
        } catch (\Throwable $exception) {
            Log::error('Soldado creation failed — rolled back.', [
                'error' => $exception->getMessage(),
            ]);

            Notification::make()
                ->title('No se pudo guardar el soldado — no se creó nada.')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    /**
     * Redirect to the detail (view) page after creating the soldado.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
