<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El correo del usuario siempre se guarda en minúscula (y sin espacios), sin importar cómo
 * llegue, para que el login no falle por diferencias de mayúsculas.
 */
class UserEmailLowercaseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lowercases_the_email_on_create(): void
    {
        $user = User::factory()->create(['email' => '  Alvarotor@Payoneer.COM ']);

        $this->assertSame('alvarotor@payoneer.com', $user->fresh()->email);
    }

    #[Test]
    public function it_lowercases_the_email_on_update(): void
    {
        $user = User::factory()->create();

        $user->update(['email' => 'MixedCase@Example.Com']);

        $this->assertSame('mixedcase@example.com', $user->fresh()->email);
    }
}
