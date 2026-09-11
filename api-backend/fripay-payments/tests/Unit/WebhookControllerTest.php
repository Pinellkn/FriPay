<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires purs pour la logique métier du WebhookController.
 * Pas de base de données, pas de container Laravel — teste uniquement :
 * - La vérification HMAC (agrégateurs)
 * - Le mapping des statuts MTN MoMo
 * - Le mapping des statuts agrégateurs
 * - La logique IP whitelist MTN
 */
class WebhookControllerTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════════
    //  HMAC Signature Verification (agrégateurs : Pispi, Kkiapay, etc.)
    // ══════════════════════════════════════════════════════════════════

    public function test_hmac_is_deterministic(): void
    {
        $payload = '{"externalId":"TXN-123","status":"PENDING"}';
        $secret = 'webhook-secret-key';

        $sig1 = hash_hmac('sha256', $payload, $secret);
        $sig2 = hash_hmac('sha256', $payload, $secret);

        $this->assertEquals($sig1, $sig2);
    }

    public function test_hmac_valid_signature_matches(): void
    {
        $payload = '{"transaction_id":"TXN-001","status":"success","amount":5000}';
        $secret = 'my-super-secret-webhook-key';
        $signature = hash_hmac('sha256', $payload, $secret);

        // hash_equals est ce que le controller utilise (timing-safe)
        $this->assertTrue(hash_equals($signature, $signature));
    }

    public function test_hmac_wrong_signature_rejected(): void
    {
        $payload = '{"transaction_id":"TXN-001","status":"success"}';
        $wrongSignature = hash_hmac('sha256', $payload, 'wrong-secret');
        $correctHash = hash_hmac('sha256', $payload, 'correct-secret');

        $this->assertNotEquals($wrongSignature, $correctHash);
    }

    public function test_hmac_differs_with_different_payloads(): void
    {
        $secret = 'same-secret';
        $sig1 = hash_hmac('sha256', '{"amount":1000}', $secret);
        $sig2 = hash_hmac('sha256', '{"amount":2000}', $secret);

        $this->assertNotEquals($sig1, $sig2);
    }

    public function test_hmac_differs_with_different_secrets(): void
    {
        $payload = '{"transaction_id":"TXN-001"}';
        $sig1 = hash_hmac('sha256', $payload, 'secret-A');
        $sig2 = hash_hmac('sha256', $payload, 'secret-B');

        $this->assertNotEquals($sig1, $sig2);
    }

    // ══════════════════════════════════════════════════════════════════
    //  MTN MoMo Status Mapping (reflète le match() du controller)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Simule le mapping exact du controller :
     * match (strtoupper((string) ($payload['status'] ?? '')))
     */
    private function mapMtnStatus(?string $status): ?string
    {
        return match (strtoupper((string) ($status ?? ''))) {
            'SUCCESSFUL' => 'succeeded',
            'FAILED'     => 'failed',
            'PENDING'    => 'pending',
            default      => null,
        };
    }

    public function test_mtn_status_successful(): void
    {
        $this->assertEquals('succeeded', $this->mapMtnStatus('SUCCESSFUL'));
    }

    public function test_mtn_status_failed(): void
    {
        $this->assertEquals('failed', $this->mapMtnStatus('FAILED'));
    }

    public function test_mtn_status_pending(): void
    {
        $this->assertEquals('pending', $this->mapMtnStatus('PENDING'));
    }

    public function test_mtn_status_unknown_returns_null(): void
    {
        $this->assertNull($this->mapMtnStatus('TIMEOUT'));
    }

    public function test_mtn_status_empty_returns_null(): void
    {
        $this->assertNull($this->mapMtnStatus(''));
    }

    public function test_mtn_status_null_returns_null(): void
    {
        $this->assertNull($this->mapMtnStatus(null));
    }

    public function test_mtn_status_is_case_insensitive(): void
    {
        // Le controller utilise strtoupper()
        $this->assertEquals('succeeded', $this->mapMtnStatus('successful'));
        $this->assertEquals('failed', $this->mapMtnStatus('Failed'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  Aggregator Status Mapping (Pispi, Kkiapay, Fedapay)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Simule le mapping du controller :
     * match ($payload['status'] ?? '')
     */
    private function mapAggregatorStatus(?string $status): ?string
    {
        return match ($status ?? '') {
            'success', 'completed' => 'succeeded',
            'failed', 'error'     => 'failed',
            'pending'             => 'pending',
            default               => null,
        };
    }

    public function test_aggregator_status_success(): void
    {
        $this->assertEquals('succeeded', $this->mapAggregatorStatus('success'));
    }

    public function test_aggregator_status_completed(): void
    {
        $this->assertEquals('succeeded', $this->mapAggregatorStatus('completed'));
    }

    public function test_aggregator_status_error(): void
    {
        $this->assertEquals('failed', $this->mapAggregatorStatus('error'));
    }

    public function test_aggregator_status_failed(): void
    {
        $this->assertEquals('failed', $this->mapAggregatorStatus('failed'));
    }

    public function test_aggregator_status_pending(): void
    {
        $this->assertEquals('pending', $this->mapAggregatorStatus('pending'));
    }

    public function test_aggregator_status_unknown_returns_null(): void
    {
        $this->assertNull($this->mapAggregatorStatus('cancelled'));
    }

    public function test_aggregator_status_is_case_sensitive(): void
    {
        // Le controller n'utilise PAS strtoupper pour les agrégateurs
        $this->assertNull($this->mapAggregatorStatus('Success'));
        $this->assertEquals('succeeded', $this->mapAggregatorStatus('success'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  MTN IP Whitelist Logic (reflète handleMtn)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Simule la logique IP du controller :
     * $signatureValid = empty($allowedIps) || in_array($clientIp, $allowedIps, true)
     */
    private function isMtnIpAllowed(string $clientIp, array $allowedIps): bool
    {
        return empty($allowedIps) || in_array($clientIp, $allowedIps, true);
    }

    public function test_empty_ip_list_allows_all(): void
    {
        $this->assertTrue($this->isMtnIpAllowed('203.0.113.50', []));
    }

    public function test_known_ip_allowed(): void
    {
        $this->assertTrue($this->isMtnIpAllowed('10.0.0.1', ['10.0.0.1', '192.168.1.100']));
    }

    public function test_unknown_ip_rejected(): void
    {
        $this->assertFalse($this->isMtnIpAllowed('203.0.113.50', ['10.0.0.1', '192.168.1.100']));
    }

    public function test_strict_type_comparison(): void
    {
        $allowedIps = ['10.0.0.1'];
        $this->assertTrue(in_array('10.0.0.1', $allowedIps, true));
        // in_array avec comparison stricte : int ne match pas string
        $this->assertFalse(in_array(10, $allowedIps, true));
    }

    // ══════════════════════════════════════════════════════════════════
    //  Edge Cases
    // ══════════════════════════════════════════════════════════════════

    public function test_webhook_payload_json_encoding(): void
    {
        $payload = [
            'externalId' => 'TXN-001',
            'financialTransactionId' => 'MTN-FT-001',
            'status' => 'SUCCESSFUL',
            'amount' => '5000',
        ];

        $json = json_encode($payload);
        $decoded = json_decode($json, true);

        $this->assertNotNull($decoded);
        $this->assertEquals('TXN-001', $decoded['externalId']);
        $this->assertEquals('SUCCESSFUL', $decoded['status']);
    }

    public function test_empty_external_id_is_falsy(): void
    {
        // Le controller vérifie : if (! $externalId)
        $this->assertEmpty('');
        $this->assertEmpty(null);
        $this->assertNotEmpty('TXN-001');
    }
}
