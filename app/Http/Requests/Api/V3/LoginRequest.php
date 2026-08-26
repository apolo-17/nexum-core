<?php

namespace App\Http\Requests\Api\V3;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the credentials payload for the login endpoint.
 */
class LoginRequest extends FormRequest
{
    /**
     * All API requests are allowed — authorization is handled by the guard.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza el correo a minúscula (y sin espacios) antes de validar y autenticar, para
     * que el login no dependa de cómo lo escriba el usuario.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * Return the validation rules for login credentials.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
