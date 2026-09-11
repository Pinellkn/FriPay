<?php

namespace Tests\Unit;

use App\Models\OtpCode;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests unitaires pour OtpService.
 *
 * Couvre :
 * - Génération (création DB, hash, invalidation, format du code)
 * - Vérification (succès, échec, expiration, max tentatives, consommé)
 * - Rate limiting (5 OTP / 10 min)
 * - Sécurité (code jamais exposé, purpose isolé, code 6 chiffres)
 */
class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private OtpService $otpService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otpService = app(OtpService::class);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Génération
    // ══════════════════════════════════════════════════════════════════

    public function test_generate_creates_database_record(): void
    {
        $result = $this->otpService->generate('+22997000001', 'registration');

        $this->assertArrayHasKey('otp_id', $result);
        $this->assertArrayHasKey('expires_in', $result);
        $this->assertEquals(300, $result['expires_in']);

        // Le code ne doit JAMAIS être retourné (fix C4)
        $this->assertArrayNotHasKey('code', $result);

        $otp = OtpCode::find($result['otp_id']);
        $this->assertNotNull($otp);
        $this->assertEquals('+22997000001', $otp->phone_number);
        $this->assertEquals('registration', $otp->purpose);
        $this->assertFalse($otp->consumed);
        $this->assertEquals(0, $otp->attempts);
    }

    public function test_generate_returns_hashed_code_not_plaintext(): void
    {
        $this->otpService->generate('+22997000002', 'login');
        $otp = OtpCode::where('phone_number', '+22997000002')
            ->where('purpose', 'login')
            ->latest()
            ->first();

        // Le hash ne doit pas ressembler à du texte brut (6 chiffres)
        $this->assertDoesNotMatchRegularExpression('/^\d{6}$/', $otp->code_hash);
        // Un hash bcrypt fait toujours plus de 50 caractères
        $this->assertGreaterThan(50, strlen($otp->code_hash));
    }

    public function test_generate_invalidates_previous_unused_codes(): void
    {
        $result1 = $this->otpService->generate('+22997000003', 'login');
        $otp1 = OtpCode::find($result1['otp_id']);
        $this->assertFalse($otp1->consumed);

        // Générer un autre → le premier doit être marqué consumed
        $this->otpService->generate('+22997000003', 'login');
        $otp1->refresh();
        $this->assertTrue($otp1->consumed);
    }

    public function test_generate_does_not_invalidate_different_purpose(): void
    {
        $result1 = $this->otpService->generate('+22997000009', 'registration');
        $otp1 = OtpCode::find($result1['otp_id']);

        // Générer un OTP pour un purpose différent
        $this->otpService->generate('+22997000009', 'login');
        $otp1->refresh();

        // Le premier OTP (registration) ne doit PAS être invalidé
        $this->assertFalse($otp1->consumed);
    }

    public function test_generate_code_is_bcrypt_hash(): void
    {
        $this->otpService->generate('+22997000011', 'registration');
        $otp = OtpCode::where('phone_number', '+22997000011')->latest()->first();

        // Le code hashé doit avoir le format bcrypt ($2y$...)
        $this->assertStringStartsWith('$2y$', $otp->code_hash);
    }

    public function test_generate_sets_correct_expiry(): void
    {
        $before = now();
        $result = $this->otpService->generate('+22997000011', 'registration');
        $after = now();

        $otp = OtpCode::find($result['otp_id']);
        $this->assertEquals(300, $result['expires_in']);

        // L'expiration doit être dans ~5 minutes
        $this->assertTrue($otp->expires_at->greaterThanOrEqualTo($before->addSeconds(299)));
        $this->assertTrue($otp->expires_at->lessThanOrEqualTo($after->addSeconds(301)));
    }

    // ══════════════════════════════════════════════════════════════════
    //  Vérification — Succès
    // ══════════════════════════════════════════════════════════════════

    public function test_verify_success_with_correct_code(): void
    {
        $code = '123456';
        $otp = OtpCode::create([
            'phone_number' => '+22997000004',
            'code_hash'    => Hash::make($code),
            'purpose'      => 'login',
            'attempts'     => 0,
            'consumed'     => false,
            'expires_at'   => now()->addMinutes(5),
        ]);

        $result = $this->otpService->verify('+22997000004', $code, 'login');

        $this->assertTrue($result);
        $otp->refresh();
        $this->assertTrue($otp->consumed);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Vérification — Échec
    // ══════════════════════════════════════════════════════════════════

    public function test_verify_failure_with_wrong_code(): void
    {
        $otp = OtpCode::create([
            'phone_number' => '+22997000005',
            'code_hash'    => Hash::make('111111'),
            'purpose'      => 'registration',
            'attempts'     => 0,
            'consumed'     => false,
            'expires_at'   => now()->addMinutes(5),
        ]);

        $result = $this->otpService->verify('+22997000005', '999999', 'registration');

        $this->assertFalse($result);
        $otp->refresh();
        $this->assertEquals(1, $otp->attempts);
        $this->assertFalse($otp->consumed);
    }

    public function test_verify_rejects_expired_otp(): void
    {
        $otp = OtpCode::create([
            'phone_number' => '+22997000006',
            'code_hash'    => Hash::make('123456'),
            'purpose'      => 'login',
            'attempts'     => 0,
            'consumed'     => false,
            'expires_at'   => now()->subMinute(),
        ]);

        $result = $this->otpService->verify('+22997000006', '123456', 'login');

        $this->assertFalse($result);
    }

    public function test_verify_rejects_already_consumed_otp(): void
    {
        $otp = OtpCode::create([
            'phone_number' => '+22997000007',
            'code_hash'    => Hash::make('123456'),
            'purpose'      => 'login',
            'attempts'     => 0,
            'consumed'     => true,
            'expires_at'   => now()->addMinutes(5),
        ]);

        $result = $this->otpService->verify('+22997000007', '123456', 'login');

        $this->assertFalse($result);
    }

    public function test_verify_rejects_after_max_attempts(): void
    {
        $otp = OtpCode::create([
            'phone_number' => '+22997000008',
            'code_hash'    => Hash::make('123456'),
            'purpose'      => 'login',
            'attempts'     => 5,
            'consumed'     => false,
            'expires_at'   => now()->addMinutes(5),
        ]);

        $result = $this->otpService->verify('+22997000008', '123456', 'login');

        $this->assertFalse($result);
    }

    public function test_verify_wrong_purpose_returns_false(): void
    {
        $otp = OtpCode::create([
            'phone_number' => '+22997000012',
            'code_hash'    => Hash::make('123456'),
            'purpose'      => 'registration',
            'attempts'     => 0,
            'consumed'     => false,
            'expires_at'   => now()->addMinutes(5),
        ]);

        $result = $this->otpService->verify('+22997000012', '123456', 'login');

        $this->assertFalse($result);
    }

    public function test_verify_no_otp_exists_returns_false(): void
    {
        $result = $this->otpService->verify('+22999999999', '123456', 'registration');

        $this->assertFalse($result);
    }

    public function test_verify_increments_attempts_on_wrong_code(): void
    {
        $otp = OtpCode::create([
            'phone_number' => '+22997000013',
            'code_hash'    => Hash::make('123456'),
            'purpose'      => 'login',
            'attempts'     => 0,
            'consumed'     => false,
            'expires_at'   => now()->addMinutes(5),
        ]);

        // 3 mauvais essais
        $this->otpService->verify('+22997000013', '000000', 'login');
        $this->otpService->verify('+22997000013', '111111', 'login');
        $this->otpService->verify('+22997000013', '222222', 'login');

        $otp->refresh();
        $this->assertEquals(3, $otp->attempts);
    }

    public function test_verify_increments_attempts_on_success(): void
    {
        $otp = OtpCode::create([
            'phone_number' => '+22997000014',
            'code_hash'    => Hash::make('123456'),
            'purpose'      => 'login',
            'attempts'     => 0,
            'consumed'     => false,
            'expires_at'   => now()->addMinutes(5),
        ]);

        $result = $this->otpService->verify('+22997000014', '123456', 'login');

        $this->assertTrue($result);
        $otp->refresh();
        // Le compteur est incrémenté AVANT la vérification du hash
        $this->assertEquals(1, $otp->attempts);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Rate Limiting
    // ══════════════════════════════════════════════════════════════════

    public function test_rate_limiting_not_triggered_with_few_otps(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->otpService->generate('+22997000020', 'registration');
        }

        $this->assertFalse($this->otpService->isRateLimited('+22997000020'));
    }

    public function test_rate_limiting_triggered_at_threshold(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->otpService->generate('+22997000021', 'registration');
        }

        $this->assertTrue($this->otpService->isRateLimited('+22997000021'));
    }

    public function test_rate_limiting_is_per_phone_number(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->otpService->generate('+22997000022', 'registration');
        }

        $this->assertFalse($this->otpService->isRateLimited('+22997000023'));
    }

    public function test_rate_limiting_only_counts_recent_otps(): void
    {
        // Insérer des OTP anciens (> 10 min) via DB brut avec UUID explicite
        // pour éviter que les timestamps ne soient écrasés par le create()
        $phone = '+22997000024';
        for ($i = 0; $i < 5; $i++) {
            DB::table('otp_codes')->insert([
                'id'           => (string) Str::uuid(),
                'phone_number' => $phone,
                'code_hash'    => Hash::make('123456'),
                'purpose'      => 'registration',
                'attempts'     => 0,
                'consumed'     => 0,
                'expires_at'   => now()->subMinutes(6)->toDateTimeString(),
                'created_at'   => now()->subMinutes(11)->toDateTimeString(),
                'updated_at'   => now()->subMinutes(11)->toDateTimeString(),
            ]);
        }

        // Les OTP de > 10 min ne comptent pas → pas de rate limiting
        $this->assertFalse($this->otpService->isRateLimited($phone));
    }

    // ══════════════════════════════════════════════════════════════════
    //  Sécurité
    // ══════════════════════════════════════════════════════════════════

    public function test_code_never_exposed_in_response(): void
    {
        $result = $this->otpService->generate('+22997000030', 'registration');

        $this->assertArrayNotHasKey('code', $result);
        $this->assertArrayNotHasKey('otp_code', $result);
        $this->assertArrayNotHasKey('hash', $result);
        $this->assertArrayHasKey('otp_id', $result);
        $this->assertArrayHasKey('expires_in', $result);
    }

    public function test_otp_code_hash_is_unique_per_generation(): void
    {
        $this->otpService->generate('+22997000031', 'registration');
        $otp1 = OtpCode::where('phone_number', '+22997000031')->latest()->first();

        $this->otpService->generate('+22997000032', 'registration');
        $otp2 = OtpCode::where('phone_number', '+22997000032')->latest()->first();

        $this->assertNotEquals($otp1->code_hash, $otp2->code_hash);
    }

    public function test_successful_verify_prevents_reuse(): void
    {
        $code = '654321';
        $otp = OtpCode::create([
            'phone_number' => '+22997000033',
            'code_hash'    => Hash::make($code),
            'purpose'      => 'login',
            'attempts'     => 0,
            'consumed'     => false,
            'expires_at'   => now()->addMinutes(5),
        ]);

        $result1 = $this->otpService->verify('+22997000033', $code, 'login');
        $this->assertTrue($result1);

        $result2 = $this->otpService->verify('+22997000033', $code, 'login');
        $this->assertFalse($result2);
    }
}
