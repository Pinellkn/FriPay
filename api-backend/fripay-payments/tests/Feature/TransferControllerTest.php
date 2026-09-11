<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\TransactionStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests Feature pour TransferController (fripay-payments).
 *
 * Couvre :
 * - POST /api/v1/transfers/quote (simulation de frais)
 * - POST /api/v1/transfers (initiation)
 * - GET /api/v1/transfers/{id} (suivi)
 * - GET /api/v1/transfers (liste)
 * - POST /api/v1/transfers/{id}/cancel (annulation)
 *
 * Les tables users / linked_accounts / operators sont creees manuellement
 * car elles appartiennent aux autres microservices.
 */
class TransferControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $userId = 'test-user-tx-1';
    private string $accountId = '';
    private string $recipientPhone = '+2290197000101';

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountId = (string) Str::uuid();
        Config::set('services.mtn.allowed_ips', []);
        $this->createSharedTablesAndFixtures();
    }

    /**
     * Cree les tables partagees et les donnees de reference pour les FK.
     */
    private function createSharedTablesAndFixtures(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('users')) {
            DB::statement('CREATE TABLE users (
                id TEXT PRIMARY KEY,
                phone_number TEXT NOT NULL UNIQUE,
                first_name TEXT,
                last_name TEXT,
                pin_hash TEXT,
                status TEXT DEFAULT "active",
                kyc_status TEXT DEFAULT "pending",
                client_type TEXT DEFAULT "P",
                preferred_language TEXT DEFAULT "fr",
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }

        if (!DB::getSchemaBuilder()->hasTable('linked_accounts')) {
            DB::statement('CREATE TABLE linked_accounts (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                phone_number TEXT NOT NULL,
                operator_id INTEGER,
                account_label TEXT,
                msisdn TEXT,
                is_primary INTEGER DEFAULT 0,
                status TEXT DEFAULT "active",
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }

        if (!DB::getSchemaBuilder()->hasTable('operators')) {
            DB::statement('CREATE TABLE operators (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                country_code TEXT DEFAULT "BJ",
                active INTEGER DEFAULT 1,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }

        // Seed operators
        if (DB::table('operators')->count() === 0) {
            DB::table('operators')->insert([
                ['id' => 1, 'code' => 'MTN_MOMO', 'name' => 'MTN Mobile Money', 'country_code' => 'BJ', 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['id' => 2, 'code' => 'MOOV_MONEY', 'name' => 'Moov Money', 'country_code' => 'BJ', 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        // Seed phone prefix — nécessaire pour OperatorDetectionService::detect().
        // Le destinataire ($this->recipientPhone, +229 01...) doit être détecté
        // comme opérateur Moov (id 2), destination du corridor de test ci-dessous.
        if (DB::table('phone_prefixes')->count() === 0) {
            DB::table('phone_prefixes')->insert([
                'operator_id' => 2,
                'prefix' => '22901',
                'country_code' => 'BJ',
            ]);
        }

        // Seed user
        // pin_hash correspond au PIN '12345' utilise par defaut dans les
        // tests d'initiation de transfert (AuthService::verifyPin exige un
        // pin_hash non nul, sinon il rejette systematiquement avec 401).
        if (DB::table('users')->where('id', $this->userId)->count() === 0) {
            DB::table('users')->insert([
                'id' => $this->userId,
                'phone_number' => '+22997000100',
                'first_name' => 'Transfer',
                'last_name' => 'Tester',
                'pin_hash' => password_hash('12345', PASSWORD_BCRYPT),
                'status' => 'active',
                'kyc_status' => 'completed',
                'client_type' => 'P',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Seed linked account
        if (DB::table('linked_accounts')->where('id', $this->accountId)->count() === 0) {
            DB::table('linked_accounts')->insert([
                'id' => $this->accountId,
                'user_id' => $this->userId,
                'phone_number' => '+22997000100',
                'operator_id' => 1,
                'msisdn' => '+22997000100',
                'is_primary' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Seed corridor
        if (DB::table('corridors')->count() === 0) {
            DB::table('corridors')->insert([
                'source_operator_id' => 1,
                'destination_operator_id' => 2,
                'rail' => 'aggregator',
                'aggregator_provider' => 'pispi',
                'priority' => 1,
                'fee_type' => 'percentage',
                'fee_value' => 1.5,
                'fee_cap' => null,
                'min_amount' => 100,
                'max_amount' => 500000,
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createToken(): string
    {
        $user = new \App\Models\User();
        $user->id = $this->userId;
        return $user->createToken('test-token', ['*'])->plainTextToken;
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->createToken()];
    }

    private function insertTransaction(string $reference, string $status = 'pending'): string
    {
        $uuid = (string) Str::uuid();
        DB::table('transactions')->insert([
            'id'                     => $uuid,
            'reference'              => $reference,
            'idempotency_key'        => "test-uuid-{$reference}",
            'sender_user_id'         => $this->userId,
            'sender_account_id'      => $this->accountId,
            'recipient_phone'        => '+2290197000101',
            'recipient_operator_id'  => 2,
            'amount'                 => 5000,
            'currency'               => 'XOF',
            'fee_amount'             => 75,
            'total_debited'          => 5075,
            'rail_used'              => 'aggregator',
            'aggregator_provider'    => 'pispi',
            'corridor_id'            => null,
            'status'                 => $status,
            'client_type_snapshot'   => 'P',
            'initiated_at'           => now()->toDateTimeString(),
            'created_at'             => now()->toDateTimeString(),
            'updated_at'             => now()->toDateTimeString(),
        ]);

        return $uuid;
    }

    // ─── POST /api/v1/transfers/quote ──────────────────────────────

    public function test_quote_returns_fee_calculation(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/transfers/quote', [
                'sender_account_id' => $this->accountId,
                'recipient_phone' => '+2290197000101',
                'amount' => '10000',
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'amount',
            'fee_amount',
            'total_debited',
            'rail',
            'quote_token',
            'estimated_delivery_seconds',
        ]);
        $response->assertJson([
            'amount' => 10000,
        ]);
    }

    public function test_quote_rejects_amount_out_of_range(): void
    {
        // Au-dessus de min:100 (validation du FormRequest), mais au-dessus du
        // max_amount du corridor (500000, cf. fixture) — pour exercer la
        // logique métier de TransferService, pas juste la validation d'entrée.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/transfers/quote', [
                'sender_account_id' => $this->accountId,
                'recipient_phone' => '+2290197000101',
                'amount' => '600000',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'type' => 'AMOUNT_OUT_OF_RANGE',
        ]);
    }

    public function test_quote_rejects_invalid_phone(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/transfers/quote', [
                'sender_account_id' => $this->accountId,
                'recipient_phone' => 'invalid',
                'amount' => '10000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('recipient_phone');
    }

    public function test_quote_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/transfers/quote', [
            'sender_account_id' => $this->accountId,
            'recipient_phone' => '+2290197000101',
            'amount' => '10000',
        ]);

        $response->assertStatus(401);
    }

    // ─── POST /api/v1/transfers (initiate) ─────────────────────────

    public function test_initiate_creates_transaction(): void
    {
        // D'abord obtenir un devis
        $quoteResponse = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/transfers/quote', [
                'sender_account_id' => $this->accountId,
                'recipient_phone' => '+2290197000101',
                'amount' => '10000',
            ]);

        $quoteToken = $quoteResponse->json('quote_token');

        // Initier le transfert
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/transfers', [
                'quote_token' => $quoteToken,
                'sender_account_id' => $this->accountId,
                'recipient_phone' => '+2290197000101',
                'amount' => '10000',
                'pin' => '12345',
            ]);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'transaction_id',
            'reference',
            'status',
        ]);
        $response->assertJson([
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('transactions', [
            'sender_user_id' => $this->userId,
            'status' => 'pending',
        ]);
    }

    public function test_initiate_rejects_expired_quote(): void
    {
        // Simuler un devis expire
        $quoteToken = Str::random(32);
        Cache::put('quote_' . $quoteToken, [
            'sender_account_id' => $this->accountId,
            'recipient_phone' => '+2290197000101',
            'amount' => 10000,
            'fee_amount' => 150,
            'total_debited' => 10150,
            'recipient_operator_id' => 2,
            'corridor_id' => 1,
            'rail' => 'aggregator',
            'expires_at' => now()->subMinute(),
        ], 10);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/transfers', [
                'quote_token' => $quoteToken,
                'sender_account_id' => $this->accountId,
                'recipient_phone' => '+2290197000101',
                'amount' => '10000',
                'pin' => '12345',
            ]);

        $response->assertStatus(410);
        $response->assertJson([
            'type' => 'QUOTE_EXPIRED',
        ]);
    }

    public function test_initiate_rejects_invalid_pin(): void
    {
        // Creer un user avec un PIN
        DB::table('users')->where('id', $this->userId)->update([
            'pin_hash' => password_hash('12345', PASSWORD_BCRYPT),
        ]);

        // Obtenir un devis
        $quoteResponse = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/transfers/quote', [
                'sender_account_id' => $this->accountId,
                'recipient_phone' => '+2290197000101',
                'amount' => '10000',
            ]);

        $quoteToken = $quoteResponse->json('quote_token');

        // Initier avec un mauvais PIN
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/transfers', [
                'quote_token' => $quoteToken,
                'sender_account_id' => $this->accountId,
                'recipient_phone' => '+2290197000101',
                'amount' => '10000',
                'pin' => '99999',
            ]);

        $response->assertStatus(401);
        $response->assertJson([
            'type' => 'INVALID_PIN',
        ]);
    }

    // ─── GET /api/v1/transfers/{id} ────────────────────────────────

    public function test_show_returns_transaction(): void
    {
        $txnId = $this->insertTransaction('TXN-SHOW-001');

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/transfers/' . $txnId);

        $response->assertOk();
        $response->assertJson([
            'reference' => 'TXN-SHOW-001',
            'status' => 'pending',
        ]);
    }

    public function test_show_returns_404_for_nonexistent(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/transfers/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }

    // ─── GET /api/v1/transfers (index) ─────────────────────────────

    public function test_index_returns_transactions(): void
    {
        $this->insertTransaction('TXN-INDEX-001');
        $this->insertTransaction('TXN-INDEX-002', 'succeeded');

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/transfers');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['page', 'size', 'total', 'has_next'],
        ]);
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_index_filters_by_status(): void
    {
        $this->insertTransaction('TXN-FILT-001', 'pending');
        $this->insertTransaction('TXN-FILT-002', 'succeeded');

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/transfers?status=succeeded');

        $response->assertOk();
        foreach ($response->json('data') as $txn) {
            $this->assertEquals('succeeded', $txn['status']);
        }
    }

    public function test_index_sorts_by_amount(): void
    {
        $this->insertTransaction('TXN-SORT-001');
        DB::table('transactions')->where('reference', 'TXN-SORT-001')->update(['amount' => 1000]);

        $this->insertTransaction('TXN-SORT-002');
        DB::table('transactions')->where('reference', 'TXN-SORT-002')->update(['amount' => 5000]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/transfers?sort=amount');

        $response->assertOk();
        $data = $response->json('data');
        if (count($data) >= 2) {
            $this->assertLessThanOrEqual($data[1]['amount'], $data[0]['amount']);
        }
    }

    public function test_index_rejects_invalid_sort_column(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/transfers?sort=evil_column');

        // Should fallback to default sort (initiated_at)
        $response->assertOk();
    }

    // ─── POST /api/v1/transfers/{id}/cancel ────────────────────────

    public function test_cancel_cancels_pending_transaction(): void
    {
        $txnId = $this->insertTransaction('TXN-CANCEL-001', 'pending');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/transfers/' . $txnId . '/cancel');

        $response->assertOk();
        $response->assertJson([
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $txnId,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_rejects_succeeded_transaction(): void
    {
        $txnId = $this->insertTransaction('TXN-CANCEL-002', 'succeeded');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/transfers/' . $txnId . '/cancel');

        $response->assertStatus(409);
        $response->assertJson([
            'type' => 'TRANSACTION_NOT_CANCELLABLE',
        ]);
    }

    public function test_cancel_records_status_history(): void
    {
        $txnId = $this->insertTransaction('TXN-CANCEL-003', 'pending');

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/transfers/' . $txnId . '/cancel');

        $history = DB::table('transaction_status_history')
            ->where('transaction_id', $txnId)
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals('pending', $history->previous_status);
        $this->assertEquals('cancelled', $history->new_status);
        $this->assertEquals('user', $history->source);
    }

    // ─── Health check ──────────────────────────────────────────────

    public function test_health_check_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/up');

        $response->assertOk();
        $response->assertJson([
            'status' => 'ok',
            'service' => 'FriPay Payments',
            'version' => 'v1',
        ]);
    }
}
