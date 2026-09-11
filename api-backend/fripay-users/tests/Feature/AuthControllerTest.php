<?php

namespace Tests\Feature;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Tests Feature pour AuthController (fripay-users).
 *
 * Couvre :
 * - POST /api/v1/auth/register (inscription)
 * - POST /api/v1/auth/verify-otp (verification OTP → tokens)
 * - POST /api/v1/auth/login (connexion par PIN)
 * - POST /api/v1/auth/refresh-token (rafraichissement des tokens)
 * - POST /api/v1/auth/logout (deconnexion)
 */
class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seedPhonePrefixes();
    }

    private function seedPhonePrefixes(): void
    {
        // Using DB facade directly
        if (DB::table('operators')->count() === 0) {
            DB::table('operators')->insert([
                ['id' => 1, 'code' => 'MTN_MOMO', 'name' => 'MTN Mobile Money', 'country_code' => 'BJ', 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['id' => 2, 'code' => 'MOOV_MONEY', 'name' => 'Moov Money', 'country_code' => 'BJ', 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
        if (DB::table('phone_prefixes')->count() === 0) {
            DB::table('phone_prefixes')->insert([
                ['operator_id' => 1, 'prefix' => '22997', 'country_code' => 'BJ'],
                ['operator_id' => 1, 'prefix' => '22990', 'country_code' => 'BJ'],
                ['operator_id' => 1, 'prefix' => '22991', 'country_code' => 'BJ'],
                ['operator_id' => 2, 'prefix' => '22996', 'country_code' => 'BJ'],
            ]);
        }
    }

    // ─── POST /api/v1/auth/register ────────────────────────────────

    public function test_register_creates_user_and_returns_201(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'phone_number' => '+22997000001',
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'user_id',
            'phone_number',
            'otp_expires_in',
        ]);
        $response->assertJson([
            'phone_number' => '+22997000001',
        ]);

        $this->assertDatabaseHas('users', [
            'phone_number' => '+22997000001',
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'status' => 'active',
            'kyc_status' => 'pending',
        ]);
    }

    public function test_register_with_minimal_data(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'phone_number' => '+22997000002',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'phone_number' => '+22997000002',
            'first_name' => null,
            'last_name' => null,
        ]);
    }

    public function test_register_rejects_duplicate_phone(): void
    {
        User::create([
            'phone_number' => '+22997000003',
            'status' => 'active',
            'kyc_status' => 'pending',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'phone_number' => '+22997000003',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('phone_number');
    }

    public function test_register_rejects_invalid_phone_format(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'phone_number' => 'invalid',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('phone_number');
    }

    public function test_register_rejects_missing_phone(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('phone_number');
    }

    // ─── POST /api/v1/auth/verify-otp ──────────────────────────────

    public function test_verify_otp_returns_tokens_on_valid_code(): void
    {
        $user = User::create([
            'phone_number' => '+22997000010',
            'status' => 'active',
            'kyc_status' => 'pending',
        ]);

        $testCode = '123456';
        $otpRecord = \App\Models\OtpCode::create([
            'phone_number' => '+22997000010',
            'code_hash' => Hash::make($testCode),
            'purpose' => 'registration',
            'attempts' => 0,
            'consumed' => false,
            'expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'phone_number' => '+22997000010',
            'code' => $testCode,
            'purpose' => 'registration',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'access_token',
            'refresh_token',
            'expires_in',
        ]);

        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $user->id,
            'revoked' => false,
        ]);
    }

    public function test_verify_otp_rejects_invalid_code(): void
    {
        User::create([
            'phone_number' => '+22997000011',
            'status' => 'active',
            'kyc_status' => 'pending',
        ]);

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'phone_number' => '+22997000011',
            'code' => '000000',
            'purpose' => 'registration',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'type' => 'OTP_INVALID',
        ]);
    }

    public function test_verify_otp_rejects_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/verify-otp', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['phone_number', 'code', 'purpose']);
    }

    // ─── POST /api/v1/auth/login ───────────────────────────────────

    public function test_login_returns_tokens_on_valid_credentials(): void
    {
        $pin = '1234';
        $user = User::create([
            'phone_number' => '+22997000020',
            'pin_hash' => Hash::make($pin),
            'status' => 'active',
            'kyc_status' => 'completed',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone_number' => '+22997000020',
            'pin' => $pin,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'access_token',
            'refresh_token',
            'expires_in',
        ]);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
    }

    public function test_login_rejects_wrong_pin(): void
    {
        User::create([
            'phone_number' => '+22997000021',
            'pin_hash' => Hash::make('1234'),
            'status' => 'active',
            'kyc_status' => 'completed',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone_number' => '+22997000021',
            'pin' => '9999',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'type' => 'INVALID_CREDENTIALS',
        ]);
    }

    public function test_login_rejects_nonexistent_user(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'phone_number' => '+22999999999',
            'pin' => '1234',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'type' => 'INVALID_CREDENTIALS',
        ]);
    }

    public function test_login_rejects_blocked_account(): void
    {
        User::create([
            'phone_number' => '+22997000022',
            'pin_hash' => Hash::make('1234'),
            'status' => 'blocked',
            'kyc_status' => 'completed',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone_number' => '+22997000022',
            'pin' => '1234',
        ]);

        $response->assertStatus(423);
        $response->assertJson([
            'type' => 'ACCOUNT_BLOCKED',
        ]);
    }

    public function test_login_rejects_user_without_pin(): void
    {
        User::create([
            'phone_number' => '+22997000023',
            'pin_hash' => null,
            'status' => 'active',
            'kyc_status' => 'pending',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone_number' => '+22997000023',
            'pin' => '1234',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_revokes_previous_tokens(): void
    {
        $user = User::create([
            'phone_number' => '+22997000024',
            'pin_hash' => Hash::make('1234'),
            'status' => 'active',
            'kyc_status' => 'completed',
        ]);

        // Premiere connexion
        $this->postJson('/api/v1/auth/login', [
            'phone_number' => '+22997000024',
            'pin' => '1234',
        ]);

        // Deuxieme connexion
        $this->postJson('/api/v1/auth/login', [
            'phone_number' => '+22997000024',
            'pin' => '1234',
        ]);

        // Un seul token actif apres la deuxieme connexion
        $tokensAfter = $user->fresh()->tokens()->count();
        $this->assertEquals(1, $tokensAfter);
    }

    // ─── POST /api/v1/auth/refresh-token ───────────────────────────

    public function test_refresh_token_returns_new_tokens(): void
    {
        $user = User::create([
            'phone_number' => '+22997000030',
            'status' => 'active',
            'kyc_status' => 'completed',
        ]);

        $authService = app(\App\Services\AuthService::class);
        $tokens = $authService->issueTokens($user);

        $response = $this->postJson('/api/v1/auth/refresh-token', [
            'refresh_token' => $tokens['refresh_token'],
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'access_token',
            'refresh_token',
            'expires_in',
        ]);

        $this->assertNotEquals(
            $tokens['refresh_token'],
            $response->json('refresh_token')
        );
    }

    public function test_refresh_token_rejects_invalid_token(): void
    {
        $response = $this->postJson('/api/v1/auth/refresh-token', [
            'refresh_token' => 'invalid-token-12345',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'type' => 'INVALID_REFRESH_TOKEN',
        ]);
    }

    public function test_refresh_token_rejects_used_token(): void
    {
        $user = User::create([
            'phone_number' => '+22997000031',
            'status' => 'active',
            'kyc_status' => 'completed',
        ]);

        $authService = app(\App\Services\AuthService::class);
        $tokens = $authService->issueTokens($user);

        // Utiliser le refresh token
        $this->postJson('/api/v1/auth/refresh-token', [
            'refresh_token' => $tokens['refresh_token'],
        ]);

        // Reutiliser le meme refresh token (revoque)
        $response = $this->postJson('/api/v1/auth/refresh-token', [
            'refresh_token' => $tokens['refresh_token'],
        ]);

        $response->assertStatus(401);
    }

    public function test_refresh_token_rejects_missing_field(): void
    {
        $response = $this->postJson('/api/v1/auth/refresh-token', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('refresh_token');
    }

    // ─── POST /api/v1/auth/logout ──────────────────────────────────

    public function test_logout_revokes_tokens_and_sessions(): void
    {
        $user = User::create([
            'phone_number' => '+22997000040',
            'status' => 'active',
            'kyc_status' => 'completed',
        ]);

        $authService = app(\App\Services\AuthService::class);
        $tokens = $authService->issueTokens($user);

        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $user->id,
            'revoked' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $tokens['access_token'],
        ])->postJson('/api/v1/auth/logout');

        $response->assertStatus(204);

        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $user->id,
            'revoked' => true,
        ]);

        $this->assertEquals(0, $user->fresh()->tokens()->count());
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }

    // ─── Health check ──────────────────────────────────────────────

    public function test_health_check_returns_ok(): void
    {
        $response = $this->getJson('/up');

        $response->assertOk();
        $response->assertJson([
            'status' => 'up',
        ]);
    }
}
