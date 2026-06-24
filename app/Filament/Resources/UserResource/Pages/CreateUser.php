<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Create page for inviting a new team member.
 *
 * Sets a random unusable password (the invitee sets their real one via the
 * activation link), assigns the chosen role, and emails the invitation.
 */
class CreateUser extends CreateRecord
{
    /**
     * @var class-string<UserResource>
     */
    protected static string $resource = UserResource::class;

    /**
     * Seed a random password so the row satisfies the NOT NULL constraint.
     * The real password is set by the invitee through the activation link.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['password'] = Hash::make(Str::random(40));

        return $data;
    }

    /**
     * Assign the selected role and send the welcome/activation email.
     */
    protected function afterCreate(): void
    {
        $role = $this->data['role'] ?? null;

        if ($role !== null) {
            $this->record->syncRoles([$role]);
        }

        UserResource::sendInvitation($this->record->fresh());
    }

    /**
     * Redirect to the list page after creating the user.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
