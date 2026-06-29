<?php

namespace App\Filament\Resources\MiPerfilResource\Pages;

use App\Filament\Resources\MiPerfilResource;
use App\Filament\Resources\SoldadoResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Self-service edit page where the soldado completes their own profile.
 *
 * FIEL fields are virtual; they are extracted and persisted (encrypted) to
 * soldado_credentials, reusing SoldadoResource's helpers.
 */
class EditMiPerfil extends EditRecord
{
    /**
     * @var class-string<MiPerfilResource>
     */
    protected static string $resource = MiPerfilResource::class;

    /**
     * Credential data extracted from the form before the soldado row is saved.
     *
     * @var array<string, string|null>
     */
    private array $pendingCredentials = [];

    /**
     * The soldado may not delete their own profile.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * No breadcrumbs — the resource has no index page to link back to.
     *
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * Only the Save action — drop the default Cancel (it links to the missing index).
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()->label('Guardar mi perfil'),
        ];
    }

    /**
     * Strip FIEL fields before saving so they go to soldado_credentials instead.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
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
     * Update the profile and its FIEL credentials atomically.
     *
     * @param  array<string, mixed>  $data
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
            Log::error('Mi perfil update failed — rolled back.', [
                'soldado_id' => $record->getKey(),
                'error' => $exception->getMessage(),
            ]);

            Notification::make()
                ->title('No se pudieron guardar los cambios.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    /**
     * Stay on the profile page after saving.
     */
    protected function getRedirectUrl(): ?string
    {
        return null;
    }
}
