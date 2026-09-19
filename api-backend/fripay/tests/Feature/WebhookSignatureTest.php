<?php

namespace Tests\Feature;

use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests Feature pour la verification de signature des webhooks aggregator.
 *
 * Valide :
 * - Rejet sans signature
 * - Rejet avec signature invalide
 * - Acceptation avec signature HMAC valide
 * - Rejet si le secret n'est pas configure
 */
class WebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedTablesAndFixtures();
    }

    private function createSharedTablesAndFixtures(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('users')) {
            DB::statement('CREATE TABLE users (
                id TEXT PRIMARY KEY,
                phone_number TEXT NOT NULL UNIQUE,
                first_name TEXT,
                last_name TEXT,
                status TEXT DEFAULT "active",
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

        if (DB::table('users')->where('id', 'test-user-wh-1')->count() === 0) {
            DB::table('users')->insert([
                'id' => 'test-user-wh-1', 'phone_number' => '+22997000200',
                'first_name' => 'Webhook', 'last_name' => 'Test',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (DB::table('linked_accounts')->where('id', 'test-account-wh-1')->count() === 0) {
            DB::table('linked_accounts')->insert([
                'id' => 'test-account-wh-1', 'user_id' => 'test-user-wh-1',
                'phone_number' => '+22997000200', 'operator_id' => 1,
                'account_label' => 'Mobile Money', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (DB::table('operators')->count() === 0) {
            DB::table('operators')->insert([
                ['id' => 1, 'code' => 'MTN_MOMO', 'name' => 'MTN', 'country_code' => 'BJ', 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    private function postAggregatorWebhook(
        string $provider,
        array $payload,
        ?string $signature = null
    ): \Illuminate\Testing\TestResponse {
        $headers = ['X-Request-Id' => 'test-' . Str::random(8)];
        if ($signature !== null) {
            $headers['X-Signature'] = $signature;
        }

        return $this->withHeaders($headers)
            ->postJson("/api/v1/webhooks/aggregator/{$provider}", $payload);
    }

    // ─── Signature Verification ────────────────────────────────────

    public function test_rejects_webhook_without_signature(): void
    {
        Config::set('services.pispi.webhook_secret', 'test-secret-key');

        $response = $this->postAggregatorWebhook('pispi', [
            'transaction_id' => 'TXN-WH-001',
            'status' => 'success',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'type' => 'INVALID_SIGNATURE',
        ]);

        $event = WebhookEvent::where('provider', 'pispi')->latest()->first();
        $this->assertNotNull($event);
        $this->assertFalse($event->signature_valid);
    }

    public function test_rejects_webhook_with_wrong_signature(): void
    {
        $secret = 'test-secret-key';
        Config::set('services.pispi.webhook_secret', $secret);

        $payload = json_encode(['transaction_id' => 'TXN-WH-002', 'status' => 'success']);
        $wrongSignature = hash_hmac('sha256', $payload, 'wrong-secret');

        $response = $this->postAggregatorWebhook('pispi', json_decode($payload, true), $wrongSignature);

        $response->assertStatus(401);
    }

    public function test_accepts_webhook_with_valid_signature(): void
    {
        $secret = 'test-secret-key';
        Config::set('services.pispi.webhook_secret', $secret);

        $payload = ['transaction_id' => 'TXN-WH-003', 'status' => 'success'];
        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, $secret);

        $response = $this->postAggregatorWebhook('pispi', $payload, $signature);

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);
    }

    public function test_rejects_when_secret_not_configured(): void
    {
        Config::set('services.pispi.webhook_secret', '');

        $payload = ['transaction_id' => 'TXN-WH-004', 'status' => 'success'];
        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, 'any-key');

        $response = $this->postAggregatorWebhook('pispi', $payload, $signature);

        // Meme avec une signature correcte, si le secret est vide => rejet
        $response->assertStatus(401);
    }

    public function test_records_webhook_event_on_signature_failure(): void
    {
        Config::set('services.aggregator.webhook_secret', 'my-secret');

        $this->postAggregatorWebhook('aggregator', [
            'transaction_id' => 'TXN-WH-005',
            'status' => 'success',
        ]);

        $event = WebhookEvent::where('provider', 'aggregator')->latest()->first();
        $this->assertNotNull($event);
        $this->assertFalse($event->signature_valid);
        $this->assertFalse($event->processed);
    }

    public function test_is_pispi_alias_for_aggregator(): void
    {
        $secret = 'pispi-secret';
        Config::set('services.pispi.webhook_secret', $secret);

        $payload = ['transaction_id' => 'TXN-WH-006', 'status' => 'pending'];
        $signature = hash_hmac('sha256', json_encode($payload), $secret);

        $response = $this->postAggregatorWebhook('pispi', $payload, $signature);

        $response->assertOk();

        $event = WebhookEvent::where('provider', 'pispi')->latest()->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->signature_valid);
    }
}
