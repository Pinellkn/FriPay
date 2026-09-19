<?php

namespace App\Services;

use App\Models\OtpCode;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    private const MAX_ATTEMPTS = 5;
    private const CODE_TTL_SECONDS = 300; // 5 minutes

    /**
     * Generate and store an OTP code for a given phone number.
     *
     * IMPORTANT: Le code n'est JAMAIS retourné dans la réponse JSON.
     * Il doit être envoyé par SMS via un service externe.
     *
     * @return array{otp_id: int, expires_in: int, code: string}
     */
    public function generate(string $phoneNumber, string $purpose): array
    {
        // Invalidate any previous unused codes
        OtpCode::where('phone_number', $phoneNumber)
            ->where('purpose', $purpose)
            ->where('consumed', false)
            ->update(['consumed' => true]);

        $code = (string) random_int(100000, 999999);

        $otp = OtpCode::create([
            'phone_number' => $phoneNumber,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'attempts' => 0,
            'consumed' => false,
            'expires_at' => now()->addSeconds(self::CODE_TTL_SECONDS),
        ]);

        // TODO: Envoyer le code par SMS ici (Twilio / Africa's Talking / agrégateur
        // béninois — nécessite un compte + des identifiants à configurer). Tant que
        // ce n'est pas branché, on journalise le code UNIQUEMENT en environnement
        // local/dev, pour pouvoir tester le parcours OTP sans SMS réel. Ce log
        // n'est jamais accessible depuis l'app mobile ni exposé dans l'API.
        if (app()->environment(['local', 'development', 'testing'])) {
            logger()->info("[DEV] Code OTP pour {$phoneNumber} ({$purpose}): {$code}");
        }
        // SmsService::send($phoneNumber, "Votre code FriPay: {$code}");

        return [
            'otp_id' => $otp->id,
            'expires_in' => self::CODE_TTL_SECONDS,
            // Le code n'est utile à l'appelant que pour l'affichage temporaire
            // en environnement dev (voir AuthController::register) — jamais
            // exposé en dehors de local/development/testing.
            'code' => $code,
        ];
    }

    /**
     * Verify an OTP code. Returns true if valid, false otherwise.
     */
    public function verify(string $phoneNumber, string $code, string $purpose): bool
    {
        $otp = OtpCode::where('phone_number', $phoneNumber)
            ->where('purpose', $purpose)
            ->where('consumed', false)
            ->latest()
            ->first();

        if (!$otp) {
            return false;
        }

        if ($otp->expires_at->isPast()) {
            return false;
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $otp->increment('attempts');

        if (!Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->update(['consumed' => true]);

        return true;
    }

    /**
     * Check if the phone number is rate-limited for OTP requests.
     */
    public function isRateLimited(string $phoneNumber): bool
    {
        $recentCount = OtpCode::where('phone_number', $phoneNumber)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        return $recentCount >= 5; // Max 5 OTP requests per 10 minutes
    }
}
