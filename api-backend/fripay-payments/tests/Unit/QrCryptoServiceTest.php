<?php

namespace Tests\Unit;

use App\Services\QrCryptoService;
use Tests\TestCase;

class QrCryptoServiceTest extends TestCase
{
    private QrCryptoService $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crypto = new QrCryptoService();
    }

    public function test_generate_key_pair_returns_valid_ed25519_lengths(): void
    {
        $kp = $this->crypto->generateKeyPair();
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen($kp['secret_key']));
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($kp['public_key']));
    }

    public function test_signed_payload_round_trip_verifies(): void
    {
        $kp = $this->crypto->generateKeyPair();
        $signed = $this->crypto->createSignedPayload(
            5000, 'XOF', $kp['secret_key'], $kp['public_key'],
            null, now()->addMinutes(30)->toIso8601String()
        );

        $ok = $this->crypto->verifySignature($signed['payload'], $signed['signature'], $kp['public_key']);
        $this->assertTrue($ok);
    }

    public function test_tampered_payload_fails_verification(): void
    {
        $kp = $this->crypto->generateKeyPair();
        $signed = $this->crypto->createSignedPayload(
            5000, 'XOF', $kp['secret_key'], $kp['public_key'],
            null, now()->addMinutes(30)->toIso8601String()
        );

        $tampered = str_replace('"amount":5000', '"amount":50000', $signed['payload']);

        $ok = $this->crypto->verifySignature($tampered, $signed['signature'], $kp['public_key']);
        $this->assertFalse($ok);
    }

    public function test_wrong_public_key_fails_verification(): void
    {
        $kp = $this->crypto->generateKeyPair();
        $otherKp = $this->crypto->generateKeyPair();

        $signed = $this->crypto->createSignedPayload(
            1000, 'XOF', $kp['secret_key'], $kp['public_key'],
            null, now()->addMinutes(30)->toIso8601String()
        );

        $ok = $this->crypto->verifySignature($signed['payload'], $signed['signature'], $otherKp['public_key']);
        $this->assertFalse($ok);
    }

    public function test_verify_qr_integrity_accepts_valid_qr_content(): void
    {
        $kp = $this->crypto->generateKeyPair();
        $signed = $this->crypto->createSignedPayload(
            2500, 'XOF', $kp['secret_key'], $kp['public_key'],
            null, now()->addMinutes(30)->toIso8601String()
        );

        $result = $this->crypto->verifyQrIntegrity($signed['qr_content']);

        $this->assertTrue($result['valid']);
        $this->assertSame(2500, $result['data']['amount']);
    }

    public function test_verify_qr_integrity_rejects_expired_qr(): void
    {
        $kp = $this->crypto->generateKeyPair();
        $signed = $this->crypto->createSignedPayload(
            2500, 'XOF', $kp['secret_key'], $kp['public_key'],
            null, now()->subMinutes(5)->toIso8601String()
        );

        $result = $this->crypto->verifyQrIntegrity($signed['qr_content']);

        $this->assertFalse($result['valid']);
        $this->assertSame('QR Code expiré', $result['error']);
    }

    public function test_verify_qr_integrity_rejects_unknown_app_magic(): void
    {
        $bogus = json_encode([
            'app' => 'not-fripay',
            'payload' => '{}',
            'signature' => 'abc',
        ]);

        $result = $this->crypto->verifyQrIntegrity($bogus);

        $this->assertFalse($result['valid']);
    }

    public function test_public_key_base64_round_trip(): void
    {
        $kp = $this->crypto->generateKeyPair();
        $b64 = $this->crypto->publicKeyToBase64($kp['public_key']);
        $raw = $this->crypto->publicKeyFromBase64($b64);

        $this->assertSame($kp['public_key'], $raw);
    }
}
