<?php

namespace App\Services;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

class AuthService
{
    private const TOKEN_TTL_MINUTES = 15;
    private const REFRESH_TOKEN_TTL_DAYS = 30;

    /**
     * Create a new user account.
     */
    public function register(array $data): User
    {
        return User::create([
            'phone_number' => $data['phone_number'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'kyc_status' => 'pending',
            'client_type' => 'P',
            'status' => 'active',
            'preferred_language' => 'fr',
        ]);
    }

    /**
     * Issue access token and create a refresh token session.
     */
    public function issueTokens(User $user, array $deviceInfo = []): array
    {
        // Revoke existing tokens for safety
        $user->tokens()->delete();

        $token = $user->createToken('access-token', ['*'], now()->addMinutes(self::TOKEN_TTL_MINUTES));

        $refreshToken = Str::random(64);
        AuthSession::create([
            'user_id' => $user->id,
            'refresh_token_hash' => Hash::make($refreshToken),
            'token_fingerprint' => hash('sha256', substr($refreshToken, 0, 32)),
            'device_info' => json_encode($deviceInfo),
            'ip_address' => request()->ip(),
            'revoked' => false,
            'expires_at' => now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
        ]);

        $user->update(['last_login_at' => now()]);

        return [
            'access_token' => $token->plainTextToken,
            'refresh_token' => $refreshToken,
            'expires_in' => self::TOKEN_TTL_MINUTES * 60,
        ];
    }

    /**
     * Refresh tokens using a valid refresh token.
     *
     * Optimisation : lookup par empreinte token (sha256 des 32 premiers
     * caractères) pour éviter de charger toutes les sessions actives.
     * L'empreinte est stockée en clair et indexée pour une recherche O(1).
     * Hash::check est appelé uniquement sur le sous-ensemble correspondant.
     */
    public function refreshTokens(string $refreshToken): ?array
    {
        $fingerprint = hash('sha256', substr($refreshToken, 0, 32));

        $sessions = AuthSession::where('revoked', false)
            ->where('expires_at', '>', now())
            ->where('token_fingerprint', $fingerprint)
            ->get();

        foreach ($sessions as $session) {
            if (Hash::check($refreshToken, $session->refresh_token_hash)) {
                $session->update(['revoked' => true]);
                return $this->issueTokens($session->user);
            }
        }

        return null;
    }

    /**
     * Logout by revoking all user tokens and sessions.
     */
    public function logout(User $user): void
    {
        $user->tokens()->delete();
        AuthSession::where('user_id', $user->id)
            ->where('revoked', false)
            ->update(['revoked' => true]);
    }

    /**
     * Verify user PIN.
     */
    public function verifyPin(User $user, string $pin): bool
    {
        if (!$user->pin_hash) {
            return false;
        }
        return Hash::check($pin, $user->pin_hash);
    }

    /**
     * Set or update user PIN.
     */
    public function setPin(User $user, string $newPin): void
    {
        $user->update([
            'pin_hash' => Hash::make($newPin),
        ]);
    }
}
