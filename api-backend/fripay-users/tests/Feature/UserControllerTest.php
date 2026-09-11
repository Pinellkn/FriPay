<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests Feature pour UserController (fripay-users).
 *
 * Couvre :
 * - GET /api/v1/users/me (profil)
 * - PUT /api/v1/users/me (mise a jour)
 * - POST /api/v1/users/me/pin (definir/changer le PIN)
 */
class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'phone_number' => '+22997000050',
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'status' => 'active',
            'kyc_status' => 'completed',
        ]);

        $authService = app(\App\Services\AuthService::class);
        $tokens = $authService->issueTokens($this->user);
        $this->token = $tokens['access_token'];
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    // ─── GET /api/v1/users/me ──────────────────────────────────────

    public function test_show_returns_user_profile(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/users/me');

        $response->assertOk();
        $response->assertJson([
            'phone_number' => '+22997000050',
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
        ]);
    }

    public function test_show_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/users/me');

        $response->assertStatus(401);
    }

    // ─── PUT /api/v1/users/me ──────────────────────────────────────

    public function test_update_modifies_user_data(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->putJson('/api/v1/users/me', [
                'first_name' => 'Pierre',
                'last_name' => 'Martin',
            ]);

        $response->assertOk();
        $response->assertJson([
            'first_name' => 'Pierre',
            'last_name' => 'Martin',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'first_name' => 'Pierre',
            'last_name' => 'Martin',
        ]);
    }

    public function test_update_allows_overwriting_name(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->putJson('/api/v1/users/me', [
                'first_name' => 'NouveauNom',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'first_name' => 'NouveauNom',
        ]);
    }

    public function test_update_rejects_invalid_email(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->putJson('/api/v1/users/me', [
                'email' => 'not-an-email',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_update_rejects_duplicate_email(): void
    {
        User::create([
            'phone_number' => '+22997000051',
            'email' => 'taken@test.com',
            'status' => 'active',
            'kyc_status' => 'pending',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->putJson('/api/v1/users/me', [
                'email' => 'taken@test.com',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_update_allows_same_email(): void
    {
        $this->user->update(['email' => 'same@test.com']);

        $response = $this->withHeaders($this->authHeaders())
            ->putJson('/api/v1/users/me', [
                'email' => 'same@test.com',
            ]);

        $response->assertOk();
    }

    public function test_update_rejects_long_first_name(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->putJson('/api/v1/users/me', [
                'first_name' => str_repeat('a', 101),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('first_name');
    }

    // ─── POST /api/v1/users/me/pin ─────────────────────────────────

    public function test_set_pin_creates_pin(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/users/me/pin', [
                'new_pin' => '5678',
            ]);

        $response->assertStatus(204);

        $this->user->refresh();
        $this->assertNotNull($this->user->pin_hash);
        $this->assertTrue(Hash::check('5678', $this->user->pin_hash));
    }

    public function test_set_pin_requires_current_pin_if_exists(): void
    {
        $this->user->update(['pin_hash' => Hash::make('1234')]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/users/me/pin', [
                'new_pin' => '5678',
                'current_pin' => '9999',
            ]);

        $response->assertStatus(401);
        $response->assertJson([
            'type' => 'INVALID_CURRENT_PIN',
        ]);
    }

    public function test_set_pin_with_valid_current_pin(): void
    {
        $this->user->update(['pin_hash' => Hash::make('1234')]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/users/me/pin', [
                'new_pin' => '5678',
                'current_pin' => '1234',
            ]);

        $response->assertStatus(204);

        $this->user->refresh();
        $this->assertTrue(Hash::check('5678', $this->user->pin_hash));
    }

    public function test_set_pin_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/users/me/pin', [
            'new_pin' => '5678',
        ]);

        $response->assertStatus(401);
    }
}
