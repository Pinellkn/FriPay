<?php

namespace Tests\Feature;

use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests Feature pour LinkedAccountController (fripay-users).
 *
 * Couvre :
 * - GET /api/v1/users/me/accounts (liste)
 * - POST /api/v1/users/me/accounts (ajout)
 * - DELETE /api/v1/users/me/accounts/{id} (suppression)
 */
class LinkedAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed operators for FK constraints
        DB::table('operators')->insert([
            ['id' => 1, 'code' => 'MTN_MOMO', 'name' => 'MTN', 'country_code' => 'BJ', 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'code' => 'MOOV_MONEY', 'name' => 'Moov', 'country_code' => 'BJ', 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('phone_prefixes')->insert([
            ['operator_id' => 1, 'prefix' => '22997', 'country_code' => 'BJ'],
            ['operator_id' => 2, 'prefix' => '22996', 'country_code' => 'BJ'],
        ]);

        $this->user = User::create([
            'phone_number' => '+22997000060',
            'first_name' => 'Test',
            'last_name' => 'User',
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

    // ─── GET /api/v1/users/me/accounts ─────────────────────────────

    public function test_index_returns_linked_accounts(): void
    {
        $this->user->linkedAccounts()->create([
            'operator_id' => 1,
            'msisdn' => '+22997000061',
            'is_primary' => true,
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/users/me/accounts');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
        ]);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_returns_empty_when_no_accounts(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/users/me/accounts');

        $response->assertOk();
        $response->assertJson(['data' => []]);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/users/me/accounts');

        $response->assertStatus(401);
    }

    // ─── POST /api/v1/users/me/accounts ────────────────────────────

    public function test_store_creates_linked_account(): void
    {
        $response = $this->withHeaders(array_merge($this->authHeaders(), ['Idempotency-Key' => 'test-create-1']))
            ->postJson('/api/v1/users/me/accounts', [
                'msisdn' => '+22997000062',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('linked_accounts', [
            'user_id' => $this->user->id,
            'msisdn' => '+22997000062',
        ]);
    }

    public function test_store_first_account_is_primary(): void
    {
        $this->withHeaders(array_merge($this->authHeaders(), ['Idempotency-Key' => 'test-create-2']))
            ->postJson('/api/v1/users/me/accounts', [
                'msisdn' => '+22997000063',
            ]);

        $account = LinkedAccount::where('user_id', $this->user->id)->first();
        $this->assertTrue((bool) $account->is_primary);
    }

    public function test_store_rejects_unsupported_operator(): void
    {
        $response = $this->withHeaders(array_merge($this->authHeaders(), ['Idempotency-Key' => 'test-create-3']))
            ->postJson('/api/v1/users/me/accounts', [
                'msisdn' => '+22999999999',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'type' => 'OPERATOR_NOT_SUPPORTED',
        ]);
    }

    public function test_store_rejects_duplicate_account(): void
    {
        // Create first
        $this->withHeaders(array_merge($this->authHeaders(), ['Idempotency-Key' => 'test-create-4']))
            ->postJson('/api/v1/users/me/accounts', [
                'msisdn' => '+22997000064',
            ]);

        // Try to create again
        $response = $this->withHeaders(array_merge($this->authHeaders(), ['Idempotency-Key' => 'test-create-5']))
            ->postJson('/api/v1/users/me/accounts', [
                'msisdn' => '+22997000064',
            ]);

        $response->assertStatus(409);
        $response->assertJson([
            'type' => 'ACCOUNT_ALREADY_LINKED',
        ]);
    }

    public function test_store_rejects_invalid_msisdn(): void
    {
        $response = $this->withHeaders(array_merge($this->authHeaders(), ['Idempotency-Key' => 'test-create-6']))
            ->postJson('/api/v1/users/me/accounts', [
                'msisdn' => 'invalid',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('msisdn');
    }

    // ─── DELETE /api/v1/users/me/accounts/{id} ─────────────────────

    public function test_destroy_deletes_account(): void
    {
        $account = $this->user->linkedAccounts()->create([
            'operator_id' => 1,
            'msisdn' => '+22997000065',
            'is_primary' => true,
            'status' => 'active',
        ]);

        // Create a second account so we can delete the first
        $this->user->linkedAccounts()->create([
            'operator_id' => 2,
            'msisdn' => '+22996000066',
            'is_primary' => false,
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson('/api/v1/users/me/accounts/' . $account->id);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('linked_accounts', ['id' => $account->id]);
    }

    public function test_destroy_rejects_last_account(): void
    {
        $account = $this->user->linkedAccounts()->create([
            'operator_id' => 1,
            'msisdn' => '+22997000067',
            'is_primary' => true,
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson('/api/v1/users/me/accounts/' . $account->id);

        $response->assertStatus(409);
        $response->assertJson([
            'type' => 'CANNOT_DELETE_LAST_ACCOUNT',
        ]);

        $this->assertDatabaseHas('linked_accounts', ['id' => $account->id]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $response = $this->deleteJson('/api/v1/users/me/accounts/1');

        $response->assertStatus(401);
    }
}
