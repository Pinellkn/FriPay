<?php

namespace Tests\Feature;

use App\Models\OfflineQrCode;
use App\Services\QrCryptoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests Feature pour le blocage des QR marchand dans les endpoints P2P.
 *
 * Valide que :
 * - Les QR marchand (MPM/CPM) sont rejetés dans receive (fix audit)
 * - Les QR marchand sont rejetés dans redeem
 * - Les QR marchand sont rejetés dans transfer
 */
class OfflineQrMerchantBlockTest extends TestCase
{
    use RefreshDatabase;

    private QrCryptoService $crypto;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crypto = new QrCryptoService();

        // Créer les tables partagées (APRÈS les migrations)
        $this->createSharedTables();

        // Créer un utilisateur de test
        $this->userId = (string) \Illuminate\Support\Str::uuid();
        DB::table('users')->insert([
            'id'           => $this->userId,
            'phone_number' => '+22990000001',
            'first_name'   => 'Test',
            'last_name'    => 'User',
            'status'       => 'active',
            'pin_hash'     => password_hash('1234', PASSWORD_BCRYPT),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Créer un fake user compatible Sanctum
        $user = new TestUser();
        $user->id = $this->userId;
        $user->exists = true;

        Sanctum::actingAs($user);
    }

    private function createSharedTables(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('users')) {
            DB::statement('CREATE TABLE users (
                id TEXT PRIMARY KEY,
                phone_number TEXT NOT NULL UNIQUE,
                first_name TEXT,
                last_name TEXT,
                status TEXT DEFAULT "active",
                pin_hash TEXT,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }
    }

    private function createMerchantQr(string $mode = 'mpm'): OfflineQrCode
    {
        $keyPair = $this->crypto->generateKeyPair();
        $signed = $this->crypto->createSignedPayload(
            5000, 'XOF', $keyPair['secret_key'], $keyPair['public_key'],
            null, now()->addMinutes(30)->toIso8601String(), $mode
        );

        return OfflineQrCode::create([
            'uuid'               => $signed['uuid'],
            'sender_user_id'     => $this->userId,
            'merchant_user_id'   => $this->userId,
            'amount'             => 5000,
            'currency'           => 'XOF',
            'sender_public_key'  => $this->crypto->publicKeyToBase64($keyPair['public_key']),
            'signature'          => $signed['signature'],
            'qr_payload'         => $signed['qr_content'],
            'qr_mode'            => $mode,
            'qr_type'            => 'dynamic',
            'status'             => 'active',
            'expires_at'         => now()->addMinutes(30),
            'idempotency_key'    => 'test-key-' . bin2hex(random_bytes(4)),
        ]);
    }

    public function test_mpm_qr_rejected_in_receive(): void
    {
        $qr = $this->createMerchantQr('mpm');
        $response = $this->postJson('/api/v1/qr/receive', ['qr_content' => $qr->qr_payload]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'MERCHANT_QR']);
    }

    public function test_cpm_qr_rejected_in_receive(): void
    {
        $qr = $this->createMerchantQr('cpm');
        $response = $this->postJson('/api/v1/qr/receive', ['qr_content' => $qr->qr_payload]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'MERCHANT_QR']);
    }

    public function test_mpm_qr_rejected_in_redeem(): void
    {
        $qr = $this->createMerchantQr('mpm');
        $response = $this->postJson('/api/v1/qr/redeem', ['uuid' => $qr->uuid]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'MERCHANT_QR']);
    }

    public function test_cpm_qr_rejected_in_redeem(): void
    {
        $qr = $this->createMerchantQr('cpm');
        $response = $this->postJson('/api/v1/qr/redeem', ['uuid' => $qr->uuid]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'MERCHANT_QR']);
    }

    public function test_mpm_qr_rejected_in_transfer(): void
    {
        $qr = $this->createMerchantQr('mpm');
        $response = $this->postJson('/api/v1/qr/transfer', [
            'uuid' => $qr->uuid,
            'recipient_phone' => '+22997000099',
        ]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'MERCHANT_QR']);
    }

    public function test_cpm_qr_rejected_in_transfer(): void
    {
        $qr = $this->createMerchantQr('cpm');
        $response = $this->postJson('/api/v1/qr/transfer', [
            'uuid' => $qr->uuid,
            'recipient_phone' => '+22997000099',
        ]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'MERCHANT_QR']);
    }
}

/**
 * Fake User model pour les tests.
 * Implémente Authenticatable pour être compatible Sanctum::actingAs().
 */
class TestUser extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use \Laravel\Sanctum\HasApiTokens,
        \Illuminate\Auth\Authenticatable;

    protected $table = 'users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
}
