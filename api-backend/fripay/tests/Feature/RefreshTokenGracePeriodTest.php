<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RefreshTokenGracePeriodTest extends TestCase
{
    use RefreshDatabase;

    private function loginAndGetTokens(): array
    {
        // NB : UserFactory est le template Laravel par défaut (name/password)
        // et ne correspond pas au schéma users de FriPay — création directe.
        User::create([
            'phone_number' => '+2290197000101',
            'fripay_number' => '3001234567',
            'pin_hash' => Hash::make('12345'),
            'kyc_status' => 'pending',
            'client_type' => 'P',
            'status' => 'active',
            'preferred_language' => 'fr',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone_number' => '+2290197000101',
            'pin' => '12345',
        ]);

        $response->assertOk();

        return $response->json();
    }

    public function test_refresh_rotation_issues_new_token_pair(): void
    {
        $tokens = $this->loginAndGetTokens();

        $response = $this->postJson('/api/v1/auth/refresh-token', [
            'refresh_token' => $tokens['refresh_token'],
        ]);

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

        $this->assertNotSame($tokens['refresh_token'], $response->json('refresh_token'));
        $this->assertNotSame($tokens['access_token'], $response->json('access_token'));
    }

    public function test_revoked_refresh_token_is_accepted_within_grace_period(): void
    {
        $tokens = $this->loginAndGetTokens();

        // Première rotation : consomme le token initial, en émet un nouveau.
        $first = $this->postJson('/api/v1/auth/refresh-token', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        $first->assertOk();

        // Rejeu de l'ancien token (révoqué par la 1re rotation) : la grace
        // period doit le ré-accepter au lieu de tuer la session — c'est le
        // cas du client dont la réponse (nouvelle paire) a été perdue.
        $second = $this->postJson('/api/v1/auth/refresh-token', [
            'refresh_token' => $tokens['refresh_token'],
        ]);

        $second->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);
    }

    public function test_revoked_refresh_token_is_rejected_after_grace_period_expires(): void
    {
        $tokens = $this->loginAndGetTokens();

        $first = $this->postJson('/api/v1/auth/refresh-token', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        $first->assertOk();

        // Passe le temps au-delà de la grace period de 60 s.
        $this->travel(90)->seconds();

        $second = $this->postJson('/api/v1/auth/refresh-token', [
            'refresh_token' => $tokens['refresh_token'],
        ]);

        $second->assertStatus(401);
    }

    public function test_unknown_refresh_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/refresh-token', [
            'refresh_token' => str_repeat('x', 64),
        ])->assertStatus(401);
    }
}
