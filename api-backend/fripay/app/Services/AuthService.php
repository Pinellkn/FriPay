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
     * Grace period après une rotation de refresh token : l'ANCIEN token
     * reste accepté pendant ce délai. Sans ça, si la réponse du refresh
     * (nouvelle paire de tokens) est perdue par le client — timeout réseau,
     * app tuée en arrière-plan — il rejoue l'ancien token déjà révoqué et
     * la session devient DÉFINITIVEMENT irrécupérable (401 sur chaque appel,
     * refresh impossible) : l'utilisateur est forcé de se reconnecter.
     * 60 s couvre amplement un timeout HTTP (12 s côté app Flutter).
     */
    private const REFRESH_GRACE_PERIOD_SECONDS = 60;

    public function __construct(
        private readonly FripayNumberService $fripayNumbers = new FripayNumberService(),
    ) {}

    /**
     * Create a new user account.
     *
     * Le numéro FriPay (cahier §1) est généré et attribué AUTOMATIQUEMENT
     * ici : l'utilisateur ne le choisit jamais.
     */
    public function register(array $data): User
    {
        return User::create([
            'phone_number' => $data['phone_number'],
            'fripay_number' => $this->fripayNumbers->generate(),
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'kyc_status' => 'pending',
            'client_type' => 'P',
            'status' => 'active',
            'preferred_language' => 'fr',
        ]);
    }

    /**
     * Issue access token and create a refresh token session.
     *
     * Note : les tokens d'accès précédents sont supprimés ici (politique
     * single-session). Une perte de la réponse d'un refresh est tolérée
     * par la grace period de REFRESH_GRACE_PERIOD_SECONDS sur l'ancien
     * refresh token (voir refreshTokens) — c'est elle qui évite qu'une
     * rotation perdue ne rende la session client définitivement morte.
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
            // Horodaté uniquement au moment où la session est RÉVOQUÉE par
            // une rotation (voir refreshTokens) : sert de départ à la
            // grace period. Null = session jamais tournée.
            'last_rotated_at' => null,
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
     *
     * GRACE PERIOD : une session révoquée il y a moins de
     * REFRESH_GRACE_PERIOD_SECONDS reste utilisable. Cas nominal : le
     * client a bien persisté la nouvelle paire et ne présente plus jamais
     * l'ancien token — la grace period ne sert à rien. Cas d'incident :
     * le client n'a jamais reçu la nouvelle paire (timeout, perte réseau)
     * et rejoue l'ancien token — il est re-roté au lieu d'être rejeté,
     * la session survit.
     */
    public function refreshTokens(string $refreshToken): ?array
    {
        $fingerprint = hash('sha256', substr($refreshToken, 0, 32));

        $sessions = AuthSession::where('token_fingerprint', $fingerprint)
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($sessions as $session) {
            if (!Hash::check($refreshToken, $session->refresh_token_hash)) {
                continue;
            }

            $graceDeadline = $session->last_rotated_at?->addSeconds(self::REFRESH_GRACE_PERIOD_SECONDS);
            $withinGrace = $session->revoked && $graceDeadline !== null && now()->lte($graceDeadline);

            if (!$session->revoked || $withinGrace) {
                $session->update(['revoked' => true, 'last_rotated_at' => now()]);

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
