<?php

namespace Tests\Feature;

use App\Models\OfflineQrCode;
use App\Services\QrCryptoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfflineQrControllerTest extends TestCase
{
    use RefreshDatabase;

    private QrCryptoService $crypto;
    private string $userId;
    private string $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crypto = new QrCryptoService();
        $this->createSharedTables();

        $this->userId = $this->makeUser('+22990000001');
        $this->otherUserId = $this->makeUser('+22990000002');

        $user = new OfflineQrTestUser();
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

    private function makeUser(string $phone): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('users')->insert([
            'id'           => $id,
            'phone_number' => $phone,
            'first_name'   => 'Test',
            'last_name'    => 'User',
            'status'       => 'active',
            'pin_hash'     => password_hash('1234', PASSWORD_BCRYPT),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        return $id;
    }

    private function createP2pQr(string $senderId, int $amount = 5000): array
    {
        $keyPair = $this->crypto->generateKeyPair();
        $signed = $this->crypto->createSignedPayload(
            $amount, 'XOF', $keyPair['secret_key'], $keyPair['public_key'],
            null, now()->addMinutes(30)->toIso8601String(), 'mpm'
        );

        $qr = OfflineQrCode::create([
            'uuid'              => $signed['uuid'],
            'sender_user_id'    => $senderId,
            'amount'            => $amount,
            'currency'          => 'XOF',
            'sender_public_key' => $this->crypto->publicKeyToBase64($keyPair['public_key']),
            'signature'         => $signed['signature'],
            'qr_payload'        => $signed['qr_content'],
            'qr_mode'           => 'mpm',
            'qr_type'           => 'dynamic',
            'status'            => 'active',
            'expires_at'        => now()->addMinutes(30),
            'idempotency_key'   => 'test-key-' . bin2hex(random_bytes(4)),
        ]);

        return ['qr' => $qr, 'keyPair' => $keyPair, 'signed' => $signed];
    }

    public function test_receive_succeeds_for_valid_p2p_qr(): void
    {
        ['qr' => $qr] = $this->createP2pQr($this->otherUserId);

        $response = $this->postJson('/api/v1/qr/receive', ['qr_content' => $qr->qr_payload]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'received']);
        $this->assertSame('received', $qr->fresh()->status);
    }

    public function test_receive_rejects_own_qr(): void
    {
        ['qr' => $qr] = $this->createP2pQr($this->userId);

        $response = $this->postJson('/api/v1/qr/receive', ['qr_content' => $qr->qr_payload]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'SELF_TRANSFER']);
    }

    public function test_receive_rejects_pubkey_mismatch(): void
    {
        // M3 — le payload est valide et signé, mais la clé enregistrée en
        // base pour ce QR ne correspond pas (ex: enregistrement corrompu
        // ou substitué). Le fix doit rejeter, pas seulement se fier au payload.
        ['qr' => $qr] = $this->createP2pQr($this->otherUserId);
        $qr->update(['sender_public_key' => base64_encode(random_bytes(32))]);

        $response = $this->postJson('/api/v1/qr/receive', ['qr_content' => $qr->qr_payload]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'PUBKEY_MISMATCH']);
    }

    public function test_verify_endpoint_reports_active_status(): void
    {
        ['qr' => $qr] = $this->createP2pQr($this->otherUserId);

        $response = $this->postJson('/api/v1/qr/verify', ['qr_content' => $qr->qr_payload]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['valid' => true, 'status' => 'active']);
    }

    public function test_mine_lists_only_own_active_qr_codes(): void
    {
        $this->createP2pQr($this->userId, 1000);
        $this->createP2pQr($this->userId, 2000);
        $this->createP2pQr($this->otherUserId, 3000);

        $response = $this->getJson('/api/v1/qr/mine/active');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_mine_excludes_expired_qr_codes(): void
    {
        ['qr' => $qr] = $this->createP2pQr($this->userId);
        $qr->update(['expires_at' => now()->subMinute()]);

        $response = $this->getJson('/api/v1/qr/mine/active');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }
}

/**
 * Fake User model pour les tests — compatible Sanctum::actingAs().
 */
class OfflineQrTestUser extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use \Laravel\Sanctum\HasApiTokens,
        \Illuminate\Auth\Authenticatable;

    protected $table = 'users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
}
