<?php

namespace App\Services;

use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

// Cahier §2 : code de confirmation du compte envoye a l'email saisi a
// l'inscription. Meme logique que OtpService (SMS) mais pour l'email,
// avec sa propre table (voir migration 2026_09_16_110001).
class EmailVerificationService
{
    private const MAX_ATTEMPTS = 5;
    private const CODE_TTL_SECONDS = 900; // 15 minutes (plus long que le SMS, le temps d'ouvrir sa boite mail)

    /**
     * @return array{id: string, expires_in: int, code: string}
     */
    public function generate(User $user): array
    {
        EmailVerificationCode::where('user_id', $user->id)
            ->where('consumed', false)
            ->update(['consumed' => true]);

        $code = (string) random_int(100000, 999999);

        $record = EmailVerificationCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'consumed' => false,
            'expires_at' => now()->addSeconds(self::CODE_TTL_SECONDS),
        ]);

        // TEMPORAIRE : tant que MAIL_MAILER=log (voir .env), aucun email
        // n'est réellement envoyé — le message part dans les logs Laravel.
        // Des qu'un vrai mailer (SMTP/SES/Postmark...) est configure, ce
        // Mail::raw partira reellement vers $user->email.
        Mail::raw(
            "Votre code de confirmation FriPay est : {$code}\nIl expire dans 15 minutes.",
            function ($message) use ($user) {
                $message->to($user->email)->subject('FriPay — Confirmez votre compte');
            }
        );

        return [
            'id' => $record->id,
            'expires_in' => self::CODE_TTL_SECONDS,
            // Utile uniquement en environnement dev pour affichage direct
            // dans l'app tant qu'aucun vrai mailer n'est branche (voir
            // AuthController::register).
            'code' => $code,
        ];
    }

    public function verify(User $user, string $code): bool
    {
        $record = EmailVerificationCode::where('user_id', $user->id)
            ->where('consumed', false)
            ->latest()
            ->first();

        if (!$record) {
            return false;
        }

        if ($record->expires_at->isPast()) {
            return false;
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $record->increment('attempts');

        if (!Hash::check($code, $record->code_hash)) {
            return false;
        }

        $record->update(['consumed' => true]);
        $user->update(['email_verified_at' => now()]);

        return true;
    }

    public function isRateLimited(User $user): bool
    {
        $recentCount = EmailVerificationCode::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        return $recentCount >= 5;
    }
}
