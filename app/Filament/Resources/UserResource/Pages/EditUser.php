<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Edit page for a team member (name, email, role).
 *
 * The password is never edited here — it is owned by the user via the
 * activation / password-reset flow.
 */
class EditUser extends EditRecord
{
    /**
     * @var class-string<UserResource>
     */
    protected static string $resource = UserResource::class;

    /**
     * Prefill the role select with the user's current role.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['assigned_roles'] = $this->record->roles->pluck('name')->all();

        return $data;
    }

    /**
     * Sync the selected roles after saving the user's attributes.
     */
    protected function afterSave(): void
    {
        $roles = (array) ($this->data['assigned_roles'] ?? []);

        UserResource::syncRolesAndSoldado($this->record, $roles);
    }

    /**
     * Return the header actions for this page.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->record->id !== Auth::id()),
        ];
    }
}
