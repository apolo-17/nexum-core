<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use SensitiveParameter;

/**
 * Login del panel que normaliza el correo a minúscula antes de autenticar, para que no
 * importe si el usuario lo escribe con mayúsculas (Postgres distingue mayúsculas y los
 * correos se guardan en minúscula — ver App\Models\User::email()).
 */
class Login extends BaseLogin
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $credentials = parent::getCredentialsFromFormData($data);

        if (isset($credentials['email']) && is_string($credentials['email'])) {
            $credentials['email'] = mb_strtolower(trim($credentials['email']));
        }

        return $credentials;
    }
}
