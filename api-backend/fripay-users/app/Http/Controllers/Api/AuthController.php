<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\SetPinRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\AuthService;
use App\Services\EmailVerificationService;
use App\Services\FripayNumberService;
use App\Services\OperatorDetectionService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly OtpService $otpService,
        private readonly OperatorDetectionService $operatorDetection,
        private readonly FripayNumberService $fripayNumbers,
        private readonly EmailVerificationService $emailVerification,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $phoneNumber = $this->operatorDetection->normalize($data['phone_number']);

        if (!$this->operatorDetection->detect($phoneNumber)) {
            return $this->errorResponse(
                'OPERATOR_NOT_SUPPORTED', 'Opérateur non supporté', 422,
                'Ce numéro n\'est pas associé à un opérateur pris en charge.', $request
            );
        }

        $user = $this->authService->register([
            'phone_number' => $phoneNumber,
            'email' => $data['email'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
        ]);

        $otp = $this->otpService->generate($phoneNumber, 'registration');

        // Cahier §2 : code de confirmation du compte envoyé par email, en
        // plus de l'OTP SMS ci-dessus qui sert à valider le numéro.
        $emailOtp = $this->emailVerification->generate($user);

        $response = [
            'user_id' => $user->id,
            'phone_number' => $phoneNumber,
            // Numéro FriPay attribué automatiquement (cahier §1) — renvoyé
            // dès l'inscription pour que l'app puisse l'afficher.
            'fripay_number' => $user->fripay_number,
            'otp_expires_in' => $otp['expires_in'],
            'email_verification_expires_in' => $emailOtp['expires_in'],
        ];

        // TEMPORAIRE : tant qu'aucun fournisseur SMS/mailer n'est branché,
        // on renvoie les codes dans la réponse UNIQUEMENT en environnement
        // local/dev/testing, pour que l'app puisse les afficher directement
        // à l'utilisateur. À retirer dès qu'un vrai SMS/email part (voir
        // OtpService::generate et EmailVerificationService::generate).
        if (app()->environment(['local', 'development', 'testing'])) {
            $response['dev_otp_code'] = $otp['code'];
            $response['dev_email_otp_code'] = $emailOtp['code'];
        }

        return response()->json($response, 201);
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            return $this->errorResponse(
                'USER_NOT_FOUND', 'Utilisateur introuvable', 404,
                'Aucun compte trouvé avec cette adresse email.', $request
            );
        }

        if ($user->email_verified_at) {
            return response()->json(['already_verified' => true]);
        }

        if ($this->emailVerification->isRateLimited($user)) {
            return $this->errorResponse(
                'TOO_MANY_ATTEMPTS', 'Trop de tentatives', 429,
                'Trop de demandes de code. Veuillez réessayer plus tard.', $request
            );
        }

        if (!$this->emailVerification->verify($user, $data['code'])) {
            return $this->errorResponse(
                'EMAIL_CODE_INVALID', 'Code invalide', 400,
                'Le code de confirmation est invalide ou a expiré.', $request
            );
        }

        return response()->json(['email_verified' => true]);
    }

    public function resendEmailVerification(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc']]);
        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            return $this->errorResponse(
                'USER_NOT_FOUND', 'Utilisateur introuvable', 404,
                'Aucun compte trouvé avec cette adresse email.', $request
            );
        }

        if ($user->email_verified_at) {
            return response()->json(['already_verified' => true]);
        }

        if ($this->emailVerification->isRateLimited($user)) {
            return $this->errorResponse(
                'TOO_MANY_ATTEMPTS', 'Trop de tentatives', 429,
                'Trop de demandes de code. Veuillez réessayer plus tard.', $request
            );
        }

        $emailOtp = $this->emailVerification->generate($user);
        $response = ['email_verification_expires_in' => $emailOtp['expires_in']];

        if (app()->environment(['local', 'development', 'testing'])) {
            $response['dev_email_otp_code'] = $emailOtp['code'];
        }

        return response()->json($response);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $data = $request->validated();
        $phoneNumber = $this->operatorDetection->normalize($data['phone_number']);

        if ($this->otpService->isRateLimited($phoneNumber)) {
            return $this->errorResponse(
                'TOO_MANY_ATTEMPTS', 'Trop de tentatives', 429,
                'Trop de demandes de code. Veuillez réessayer plus tard.', $request
            );
        }

        $valid = $this->otpService->verify($phoneNumber, $data['code'], $data['purpose']);

        if (!$valid) {
            return $this->errorResponse(
                'OTP_INVALID', 'Code invalide', 400,
                'Le code de vérification est invalide ou a expiré.', $request
            );
        }

        $user = User::where('phone_number', $phoneNumber)->first();

        if (!$user) {
            return $this->errorResponse(
                'USER_NOT_FOUND', 'Utilisateur introuvable', 404,
                'Aucun compte trouvé avec ce numéro de téléphone.', $request
            );
        }

        $tokens = $this->authService->issueTokens($user, [
            'device' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        return response()->json($tokens);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $identifier = trim($data['phone_number']);

        // Cahier §3 : l'identifiant peut être un numéro FriPay (30 + 8
        // chiffres) ou un numéro d'opérateur (+229 01 + 8 chiffres). Le "30"
        // ne chevauche aucun préfixe opérateur, la distinction est sûre.
        if ($this->fripayNumbers->isFripayNumber($identifier)) {
            $user = User::where('fripay_number', $this->fripayNumbers->normalize($identifier))->first();
        } else {
            $user = User::where('phone_number', $this->operatorDetection->normalize($identifier))->first();
        }

        if (!$user) {
            return $this->errorResponse(
                'INVALID_CREDENTIALS', 'Identifiants invalides', 401,
                'Numéro ou PIN incorrect.', $request
            );
        }

        if ($user->status === 'blocked') {
            return $this->errorResponse(
                'ACCOUNT_BLOCKED', 'Compte bloqué', 423,
                'Votre compte a été bloqué. Contactez le support.', $request
            );
        }

        if (!$this->authService->verifyPin($user, $data['pin'])) {
            return $this->errorResponse(
                'INVALID_CREDENTIALS', 'Identifiants invalides', 401,
                'Numéro de téléphone ou PIN incorrect.', $request
            );
        }

        $tokens = $this->authService->issueTokens($user, [
            'device' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        return response()->json($tokens);
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => 'required|string']);

        $tokens = $this->authService->refreshTokens($data['refresh_token']);

        if (!$tokens) {
            return $this->errorResponse(
                'INVALID_REFRESH_TOKEN', 'Token de rafraîchissement invalide', 401,
                'Le token de rafraîchissement est invalide ou a expiré.', $request
            );
        }

        return response()->json($tokens);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());
        return response()->json(null, 204);
    }
}
