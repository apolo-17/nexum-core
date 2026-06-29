<?php

namespace Tests\Feature\Soldado;

use App\Filament\Auth\CompleteRegistration;
use App\Models\Soldado;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verifies the soldado sets their password AND uploads their data in the same form.
 */
class CompleteRegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_soldado_sets_password_and_profile_in_one_submit(): void
    {
        Role::findOrCreate('soldado', 'web');

        $user = User::create(['name' => 'Juan', 'email' => 'juan@soldados.mx', 'password' => 'temporary']);
        $user->assignRole('soldado');
        $soldado = Soldado::create([
            'name' => 'Juan',
            'email' => 'juan@soldados.mx',
            'available_for_mua' => false,
            'is_active' => true,
            'user_id' => $user->id,
        ]);

        $token = Password::broker()->createToken($user);

        Livewire::test(CompleteRegistration::class, ['email' => $user->email, 'token' => $token])
            ->fillForm([
                'password' => 'new-password-123',
                'passwordConfirmation' => 'new-password-123',
                'phone' => '5511112222',
                'rfc' => 'PEXJ800101AB1',
                'curp' => 'PEXJ800101HDFRXN09',
                'birthplace' => 'CDMX',
                'address' => 'Calle 1',
            ])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        // Password was set.
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));

        // Profile/data was saved in the same submit.
        $soldado->refresh();
        $this->assertSame('5511112222', $soldado->phone);
        $this->assertSame('PEXJ800101AB1', $soldado->rfc);
        $this->assertSame('CDMX', $soldado->birthplace);
    }
}
