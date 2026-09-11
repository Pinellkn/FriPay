<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour la configuration du gateway.
 * Valide la structure de configuration sans démarrer de serveur.
 */
class GatewayConfigTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = require __DIR__ . '/../../../fripay-gateway/config/gateway.php';
    }

    /**
     * C1 FIX: Vérifier que la section monitoring existe avec allowed_ips
     */
    public function test_monitoring_config_has_allowed_ips(): void
    {
        $this->assertArrayHasKey('monitoring', $this->config);
        $this->assertArrayHasKey('allowed_ips', $this->config['monitoring']);
        $this->assertIsArray($this->config['monitoring']['allowed_ips']);
        $this->assertNotEmpty($this->config['monitoring']['allowed_ips']);
    }

    /**
     * C1 FIX: Les IPs autorisées doivent inclure localhost par défaut
     */
    public function test_monitoring_default_ips_include_localhost(): void
    {
        $ips = $this->config['monitoring']['allowed_ips'];
        $this->assertContains('127.0.0.1', $ips);
        $this->assertContains('::1', $ips);
    }

    /**
     * E2 FIX: Les routes QR doivent être dans le service payments
     */
    public function test_payments_routes_include_qr(): void
    {
        $paymentsRoutes = $this->config['services']['payments']['routes'];
        $this->assertContains('/api/v1/qr/', $paymentsRoutes);
    }

    /**
     * Vérifier que les 3 services sont configurés
     */
    public function test_all_three_services_configured(): void
    {
        $this->assertArrayHasKey('users', $this->config['services']);
        $this->assertArrayHasKey('payments', $this->config['services']);
        $this->assertArrayHasKey('admin', $this->config['services']);
    }

    /**
     * Vérifier que le rate limiting est configuré
     */
    public function test_rate_limiting_is_configured(): void
    {
        $this->assertArrayHasKey('rate_limiting', $this->config);
        $this->assertTrue($this->config['rate_limiting']['enabled']);
        $this->assertGreaterThan(0, $this->config['rate_limiting']['bucket_size']);
    }

    /**
     * Vérifier que le circuit breaker est configuré
     */
    public function test_circuit_breaker_is_configured(): void
    {
        $this->assertArrayHasKey('circuit_breaker', $this->config);
        $this->assertTrue($this->config['circuit_breaker']['enabled']);
        $this->assertGreaterThan(0, $this->config['circuit_breaker']['failure_threshold']);
    }
}
