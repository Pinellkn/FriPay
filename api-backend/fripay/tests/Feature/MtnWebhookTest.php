<?php

namespace Tests\Feature;

use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests Feature pour les webhooks MTN MoMo.
 *
 * Valide :
 * - Rejet des webhooks depuis des IPs non autorisées (fix C3)
 * - Acceptation des webhooks depuis des IPs autorisées
 * - Mode sandbox (aucune IP configurée) → tout accepté
 * - Gestion des transactions inconnues
 * - Mise à jour du statut et historique
 * - Enregistrement des événements webhook
 */
class MtnWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.mtn.allowed_ips', ['10.0.0.1', '192.168.1.100']);

        // Créer les tables partagées et les données de référence APRÈS les migrations
        $this->createSharedTablesAndFixtures();
    }

    /**
     * Crée les tables partagées + données de référence nécessaires pour les FK.
     * Doit être appelé APRÈS les migrations car RefreshDatabase les recrée.
     */
    private function createSharedTablesAndFixtures(): void
    {
        // Table users (appartient à fripay-users)
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

        // Table linked_accounts (appartient à fripay-users)
        if (!DB::getSchemaBuilder()->hasTable('linked_accounts')) {
            DB::statement('CREATE TABLE linked_accounts (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                phone_number TEXT NOT NULL,
                operator_id INTEGER,
                account_label TEXT,
                status TEXT DEFAULT "active",
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }

        // Table operators (nécessaire pour FK recipient_operator_id)
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

        // Données de référence pour les FK
        if (DB::table('operators')->count() === 0) {
            DB::table('operators')->insert([
                'id' => 1, 'code' => 'MTN_MOMO', 'name' => 'MTN Mobile Money',
                'country_code' => 'BJ', 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (DB::table('users')->where('id', 'test-user-1')->count() === 0) {
            DB::table('users')->insert([
                'id' => 'test-user-1', 'phone_number' => '+22990000001',
                'first_name' => 'Test', 'last_name' => 'User', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (DB::table('linked_accounts')->where('id', 'test-account-1')->count() === 0) {
            DB::table('linked_accounts')->insert([
                'id' => 'test-account-1', 'user_id' => 'test-user-1',
                'phone_number' => '+22990000001', 'operator_id' => 1,
                'account_label' => 'Mobile Money', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Helper: insère une transaction directement en SQL avec UUID explicite.
     */
    private function insertTransaction(string $reference, string $status = 'pending'): string
    {
        $uuid = (string) Str::uuid();
        DB::table('transactions')->insert([
            'id'                     => $uuid,
            'reference'              => $reference,
            'idempotency_key'        => "test-uuid-{$reference}",
            'sender_user_id'         => 'test-user-1',
            'sender_account_id'      => 'test-account-1',
            'recipient_phone'        => '+22990000002',
            'recipient_operator_id'  => 1,
            'amount'                 => 5000,
            'currency'               => 'XOF',
            'fee_amount'             => 0,
            'total_debited'          => 5000,
            'rail_used'              => 'mtn_momo',
            'aggregator_provider'    => null,
            'corridor_id'            => null,
            'status'                 => $status,
            'client_type_snapshot'   => 'api',
            'initiated_at'           => now()->toDateTimeString(),
            'created_at'             => now()->toDateTimeString(),
            'updated_at'             => now()->toDateTimeString(),
        ]);

        return $uuid;
    }

    // ══════════════════════════════════════════════════════════════════
    //  IP Whitelist
    // ══════════════════════════════════════════════════════════════════

    public function test_rejects_unauthorized_ip(): void
    {
        $response = $this->postMtnWebhook(
            ['externalId' => 'TXN-001', 'status' => 'SUCCESSFUL', 'amount' => '5000'],
            '203.0.113.50'
        );

        $response->assertStatus(403);
        $response->assertJson([
            'type'   => 'UNAUTHORIZED_SOURCE',
            'status' => 403,
        ]);

        $event = WebhookEvent::where('provider', 'mtn')->latest()->first();
        $this->assertFalse($event->signature_valid);
    }

    public function test_accepts_authorized_ip(): void
    {
        $this->insertTransaction('TXN-002');

        $response = $this->postMtnWebhook(
            [
                'externalId'             => 'TXN-002',
                'financialTransactionId' => 'MTN-FT-002',
                'status'                 => 'SUCCESSFUL',
                'amount'                 => '5000',
            ],
            '10.0.0.1'
        );

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);

        $txn = DB::table('transactions')->where('reference', 'TXN-002')->first();
        $this->assertEquals('succeeded', $txn->status);
        $this->assertEquals('MTN-FT-002', $txn->external_reference);
        $this->assertNotNull($txn->completed_at);
    }

    public function test_sandbox_mode_allows_any_ip(): void
    {
        Config::set('services.mtn.allowed_ips', []);

        $response = $this->postMtnWebhook(
            ['externalId' => 'TXN-003', 'status' => 'FAILED', 'amount' => '3000'],
            '203.0.113.99'
        );

        $response->assertStatus(200);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Status Mapping
    // ══════════════════════════════════════════════════════════════════

    public function test_successful_status_updates_transaction(): void
    {
        $this->insertTransaction('TXN-100');

        $response = $this->postMtnWebhook(
            ['externalId' => 'TXN-100', 'status' => 'SUCCESSFUL', 'financialTransactionId' => 'FT-100'],
            '10.0.0.1'
        );

        $response->assertStatus(200);

        $txn = DB::table('transactions')->where('reference', 'TXN-100')->first();
        $this->assertEquals('succeeded', $txn->status);
        $this->assertEquals('FT-100', $txn->external_reference);
    }

    public function test_failed_status_updates_transaction(): void
    {
        $this->insertTransaction('TXN-101');

        $response = $this->postMtnWebhook(
            ['externalId' => 'TXN-101', 'status' => 'FAILED'],
            '10.0.0.1'
        );

        $response->assertStatus(200);

        $txn = DB::table('transactions')->where('reference', 'TXN-101')->first();
        $this->assertEquals('failed', $txn->status);
        $this->assertNotNull($txn->completed_at);
    }

    public function test_pending_status_keeps_status_pending(): void
    {
        $this->insertTransaction('TXN-102');

        $response = $this->postMtnWebhook(
            ['externalId' => 'TXN-102', 'status' => 'PENDING'],
            '10.0.0.1'
        );

        $response->assertStatus(200);

        $txn = DB::table('transactions')->where('reference', 'TXN-102')->first();
        $this->assertEquals('pending', $txn->status);
    }

    public function test_unknown_status_does_not_update_transaction(): void
    {
        $this->insertTransaction('TXN-103');

        $response = $this->postMtnWebhook(
            ['externalId' => 'TXN-103', 'status' => 'TIMEOUT'],
            '10.0.0.1'
        );

        $response->assertStatus(200);

        $txn = DB::table('transactions')->where('reference', 'TXN-103')->first();
        $this->assertEquals('pending', $txn->status);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Événements Webhook & Erreurs
    // ══════════════════════════════════════════════════════════════════

    public function test_records_webhook_event_on_success(): void
    {
        $this->insertTransaction('TXN-200');

        $this->postMtnWebhook(
            ['externalId' => 'TXN-200', 'status' => 'SUCCESSFUL'],
            '10.0.0.1'
        );

        $event = WebhookEvent::where('provider', 'mtn')->latest()->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->signature_valid);
        $this->assertTrue($event->processed);
        $this->assertNull($event->processing_error);
        $this->assertEquals('SUCCESSFUL', $event->payload['status']);
    }

    public function test_records_webhook_event_on_unauthorized_ip(): void
    {
        $this->postMtnWebhook(
            ['externalId' => 'TXN-201', 'status' => 'SUCCESSFUL'],
            '203.0.113.50'
        );

        $event = WebhookEvent::where('provider', 'mtn')->latest()->first();
        $this->assertNotNull($event);
        $this->assertFalse($event->signature_valid);
        $this->assertFalse($event->processed);
    }

    public function test_handles_missing_external_id(): void
    {
        Config::set('services.mtn.allowed_ips', []);

        $response = $this->postMtnWebhook(
            ['financialTransactionId' => 'FT-300', 'status' => 'SUCCESSFUL'],
            '10.0.0.1'
        );

        $response->assertStatus(200);

        $event = WebhookEvent::where('provider', 'mtn')->latest()->first();
        $this->assertNotNull($event->processing_error);
        $this->assertStringContainsString('Missing externalId', $event->processing_error);
    }

    public function test_handles_unknown_transaction(): void
    {
        Config::set('services.mtn.allowed_ips', []);

        $response = $this->postMtnWebhook(
            ['externalId' => 'UNKNOWN-TXN', 'status' => 'SUCCESSFUL'],
            '10.0.0.1'
        );

        $response->assertStatus(200);

        $event = WebhookEvent::where('provider', 'mtn')->latest()->first();
        $this->assertNotNull($event->processing_error);
        $this->assertStringContainsString('Transaction not found', $event->processing_error);
    }

    public function test_mtn_always_returns_200_even_on_error(): void
    {
        $response = $this->postMtnWebhook(
            ['externalId' => 'UNKNOWN', 'status' => 'SUCCESSFUL'],
            '10.0.0.1'
        );

        $response->assertStatus(200);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Cas limites
    // ══════════════════════════════════════════════════════════════════

    public function test_empty_payload_returns_200_in_sandbox(): void
    {
        Config::set('services.mtn.allowed_ips', []);

        $response = $this->postMtnWebhook([], '10.0.0.1');

        $response->assertStatus(200);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $this->insertTransaction('TXN-400');

        $payload = ['externalId' => 'TXN-400', 'status' => 'SUCCESSFUL'];
        $this->postMtnWebhook($payload, '10.0.0.1');
        $this->postMtnWebhook($payload, '10.0.0.1');

        $txn = DB::table('transactions')->where('reference', 'TXN-400')->first();
        $this->assertEquals('succeeded', $txn->status);

        // Anti-rejeu : seul le premier webhook doit être marqué "processed".
        // Le second doit être ignoré explicitement comme doublon, pas
        // re-traité (protège contre une double transition de statut).
        $processedEvents = WebhookEvent::where('provider', 'mtn')
            ->where('processed', true)
            ->count();
        $this->assertEquals(1, $processedEvents);

        $ignoredDuplicate = WebhookEvent::where('provider', 'mtn')
            ->where('processed', false)
            ->where('processing_error', 'Duplicate webhook ignored (already processed)')
            ->count();
        $this->assertEquals(1, $ignoredDuplicate);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════════

    private function postMtnWebhook(array $payload, string $ip): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'REMOTE_ADDR'  => $ip,
            'X-Request-Id' => 'test-' . bin2hex(random_bytes(4)),
        ])->postJson('/api/v1/webhooks/mtn', $payload);
    }
}
