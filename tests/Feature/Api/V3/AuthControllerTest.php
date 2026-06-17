<?php

namespace Tests\Feature\Api\V3;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Feature tests for the V3 AuthController.
 *
 * Covers the full HTTP contract: route, method, auth, response shape, and status codes.
 */
class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // POST /api/v3/auth/login
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_a_jwt_token_on_valid_login(): void
    {
        User::factory()->create([
            'email'    => 'notario@nexum.mx',
            'password' => Hash::make('secret1234'),
        ]);

        $response = $this->postJson('/api/v3/auth/login', [
            'email'    => 'notario@nexum.mx',
            'password' => 'secret1234',
        ]);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in'])
            ->assertJsonPath('token_type', 'bearer');
    }

    #[Test]
    public function it_returns_401_on_invalid_password(): void
    {
        User::factory()->create([
            'email'    => 'notario@nexum.mx',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v3/auth/login', [
            'email'    => 'notario@nexum.mx',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED)
            ->assertJsonPath('error', 'Invalid credentials');
    }

    #[Test]
    public function it_returns_401_for_non_existent_user(): void
    {
        $response = $this->postJson('/api/v3/auth/login', [
            'email'    => 'ghost@nexum.mx',
            'password' => 'any-password',
        ]);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function it_returns_422_when_email_is_missing(): void
    {
        $response = $this->postJson('/api/v3/auth/login', [
            'password' => 'secret1234',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function it_returns_422_when_password_is_missing(): void
    {
        $response = $this->postJson('/api/v3/auth/login', [
            'email' => 'notario@nexum.mx',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['password']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v3/auth/me
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_user_data_for_an_authenticated_user(): void
    {
        $user = User::factory()->create(['name' => 'Juan Notario']);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/v3/auth/me');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure(['id', 'name', 'email', 'role'])
            ->assertJsonPath('name', 'Juan Notario')
            ->assertJsonPath('email', $user->email);
    }

    #[Test]
    public function it_returns_401_when_accessing_me_without_token(): void
    {
        $this->getJson('/api/v3/auth/me')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    // -------------------------------------------------------------------------
    // POST /api/v3/auth/logout
    // -------------------------------------------------------------------------

    #[Test]
    public function it_logs_out_the_authenticated_user_successfully(): void
    {
        // JWT logout requires a real token in the Authorization header —
        // actingAs() alone does not satisfy JWT's requireToken() check.
        $token = $this->loginAndGetToken();

        $this->postJson('/api/v3/auth/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('message', 'Successfully logged out');
    }

    #[Test]
    public function it_returns_401_when_logging_out_without_a_token(): void
    {
        $this->postJson('/api/v3/auth/logout')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    // -------------------------------------------------------------------------
    // POST /api/v3/auth/refresh
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_a_new_token_on_refresh(): void
    {
        // JWT refresh also requires the current token to be present in the request.
        $token = $this->loginAndGetToken();

        $this->postJson('/api/v3/auth/refresh', [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a user, perform a real login, and return the JWT access token.
     *
     * Required for endpoints that call JWT methods needing a real token
     * (logout, refresh) — actingAs() bypasses JWT and does not set a token.
     *
     * @return string  Raw JWT string from the login response.
     */
    private function loginAndGetToken(): string
    {
        User::factory()->create([
            'email'    => 'test@nexum.mx',
            'password' => Hash::make('secret1234'),
        ]);

        $response = $this->postJson('/api/v3/auth/login', [
            'email'    => 'test@nexum.mx',
            'password' => 'secret1234',
        ]);

        return $response->json('access_token');
    }
}
