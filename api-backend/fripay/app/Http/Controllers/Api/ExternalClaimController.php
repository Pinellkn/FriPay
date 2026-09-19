<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\OfflineQrCode;
use App\Models\OfflineQrEvent;
use App\Services\OperatorDetectionService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * §6.e du cahier des charges — parcours "receveur sans compte Fripay".
 *
 * Contrôleur PUBLIC (pas d'auth Sanctum) : c'est la page web vers laquelle
 * un scanner externe redirige le receveur qui n'a pas l'appli. Protégé
 * uniquement par rate limiting IP (`qr-external-claim`) + le compteur de
 * tentatives (3 max) déjà porté par le modèle OfflineQrCode.
 *
 * @tags QR Codes — Receveur externe
 */
class ExternalClaimController extends Controller
{
    public function __construct(
        private OperatorDetectionService $operatorDetection,
        private WalletService $wallets
    ) {}

    /**
     * Infos publiques d'un QR "receveur externe" (étapes 4-5 : la page web
     * affiche le montant avant de proposer "installer l'appli" ou "saisir
     * un numéro de retrait").
     *
     * @pathParam uuid string UUID du QR Code.
     * @response status=200 {"amount":5000,"currency":"XOF","claimable":true,"attempts_remaining":3}
     * @response status=404 {"error":"QR_NOT_FOUND"}
     */
    public function lookup(string $uuid): JsonResponse
    {
        $qr = OfflineQrCode::where('uuid', $uuid)->first();

        if (!$qr) {
            return response()->json(['error' => 'QR_NOT_FOUND'], 404);
        }

        if (!$qr->isExternal()) {
            // Ce QR a un destinataire Fripay connu : pas le bon parcours.
            return response()->json(['error' => 'NOT_EXTERNAL_QR'], 422);
        }

        return response()->json([
            'uuid'               => $qr->uuid,
            'amount'             => $qr->amount,
            'currency'           => $qr->currency,
            'status'             => $qr->status,
            'claimable'          => $qr->isExternallyClaimable(),
            'already_claimed'    => $qr->external_claimed_at !== null,
            'expired'            => $qr->hasExpired(),
            'attempts_remaining' => $qr->externalAttemptsRemaining(),
        ]);
    }

    /**
     * Réclamer le QR (étapes 6-8) : code de validation à 5 chiffres + numéro
     * de retrait (n'importe quel réseau, §6.e.5). 3 tentatives max — au 3ᵉ
     * échec le QR est annulé et l'envoyeur remboursé automatiquement.
     *
     * @pathParam uuid string UUID du QR Code.
     * @bodyParam validation_code string required Code à 5 chiffres. Example: 04821
     * @bodyParam payout_phone string required Numéro où recevoir l'argent. Example: +22997000002
     *
     * @response status=200 {"message":"Retrait effectué","status":"redeemed","amount":5000}
     * @response status=422 {"error":"INVALID_CODE","attempts_remaining":2}
     * @response status=410 {"error":"MAX_ATTEMPTS_REACHED"}
     */
    public function claim(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'validation_code' => 'required|string|size:5|regex:/^\d{5}$/',
            'payout_phone'    => 'required|string|max:20',
        ]);

        $result = DB::transaction(function () use ($uuid, $validated) {
            $qr = OfflineQrCode::where('uuid', $uuid)->lockForUpdate()->first();

            if (!$qr) {
                return ['error' => 'NOT_FOUND'];
            }

            if (!$qr->isExternal()) {
                return ['error' => 'NOT_EXTERNAL_QR'];
            }

            if ($qr->external_claimed_at !== null) {
                return ['error' => 'ALREADY_CLAIMED'];
            }

            if (in_array($qr->status, [OfflineQrCode::STATUS_CANCELLED, OfflineQrCode::STATUS_REVOKED, OfflineQrCode::STATUS_EXPIRED], true)) {
                return ['error' => 'NOT_CLAIMABLE'];
            }

            if ($qr->hasExpired() || $qr->externalAttemptsRemaining() <= 0) {
                return ['error' => 'NOT_CLAIMABLE'];
            }

            // Comparaison en temps constant — évite qu'un timing attack
            // n'aide à deviner le code à 5 chiffres.
            $codeMatches = hash_equals((string) $qr->external_validation_code, $validated['validation_code']);

            if (!$codeMatches) {
                $qr->increment('external_attempts');
                $qr->refresh();

                OfflineQrEvent::create([
                    'offline_qr_code_id' => $qr->id,
                    'event_type'         => OfflineQrEvent::EVENT_EXTERNAL_ATTEMPT_FAILED,
                    'actor_user_id'      => null,
                    'metadata'           => ['attempts' => $qr->external_attempts],
                ]);

                if ($qr->externalAttemptsRemaining() <= 0) {
                    return $this->cancelAndRefund($qr);
                }

                return ['error' => 'INVALID_CODE', 'attempts_remaining' => $qr->externalAttemptsRemaining()];
            }

            // Code correct — retrait vers le numéro saisi (n'importe quel
            // réseau, §6.e.5). Le connecteur natif de disbursement par
            // opérateur (MTN/Moov/Celtiis) n'est pas encore implémenté dans
            // ce dépôt (même écart que TransferService) : on règle le QR et
            // on trace le numéro/réseau cible, prêt pour le connecteur.
            $network = $this->operatorDetection->detect($validated['payout_phone']);
            $payoutPhone = $this->operatorDetection->normalize($validated['payout_phone']);

            $qr->update([
                'status'                  => OfflineQrCode::STATUS_REDEEMED,
                'external_claimed_at'     => now(),
                'settled_at'              => now(),
                'external_payout_number'  => $payoutPhone,
                'external_payout_network' => $network?->code,
            ]);

            OfflineQrEvent::create([
                'offline_qr_code_id' => $qr->id,
                'event_type'         => OfflineQrEvent::EVENT_EXTERNAL_CLAIMED,
                'actor_user_id'      => null,
                'metadata'           => [
                    'payout_phone'   => $payoutPhone,
                    'payout_network' => $network?->code,
                ],
            ]);

            return ['qr' => $qr];
        });

        if (isset($result['error'])) {
            Log::warning('Réclamation QR externe échouée', [
                'uuid'  => $uuid,
                'error' => $result['error'],
                'ip'    => $request->ip(),
            ]);

            return match ($result['error']) {
                'NOT_FOUND'            => response()->json(['error' => 'QR_NOT_FOUND'], 404),
                'NOT_EXTERNAL_QR'      => response()->json(['error' => 'NOT_EXTERNAL_QR'], 422),
                'ALREADY_CLAIMED'      => response()->json(['error' => 'ALREADY_CLAIMED'], 422),
                'NOT_CLAIMABLE'        => response()->json(['error' => 'NOT_CLAIMABLE'], 422),
                'INVALID_CODE'         => response()->json([
                    'error'              => 'INVALID_CODE',
                    'attempts_remaining' => $result['attempts_remaining'],
                ], 422),
                'MAX_ATTEMPTS_REACHED' => response()->json([
                    'error'   => 'MAX_ATTEMPTS_REACHED',
                    'message' => 'Nombre maximal de tentatives atteint. La transaction a été annulée et remboursée à l\'expéditeur.',
                ], 410),
                default => response()->json(['error' => 'UNKNOWN'], 500),
            };
        }

        $qr = $result['qr'];

        Log::info('QR externe réclamé', ['uuid' => $qr->uuid, 'amount' => $qr->amount]);

        return response()->json([
            'message'  => 'Retrait effectué. Le règlement vers votre numéro sera traité dès que le connecteur opérateur est actif.',
            'status'   => 'redeemed',
            'amount'   => $qr->amount,
            'currency' => $qr->currency,
        ]);
    }

    /**
     * §6.e.8 — 3ᵉ échec : annulation + remboursement automatique de
     * l'expéditeur. Les fonds avaient été débités (held) dès la génération
     * du QR (cf. OfflineQrController::generate), donc le "remboursement"
     * est un vrai crédit wallet, pas juste un changement de statut.
     */
    private function cancelAndRefund(OfflineQrCode $qr): array
    {
        $qr->update([
            'status'      => OfflineQrCode::STATUS_CANCELLED,
            'refunded_at' => now(),
        ]);

        $this->wallets->credit(
            $qr->sender_user_id,
            (float) $qr->amount,
            null,
            'qr_external_claim_failed_refund',
            "Remboursement QR argent — 3 tentatives de retrait externe échouées"
        );

        // Alerte à l'ENVOYEUR (cahier des charges) : après 3 échecs du code
        // de vérification, la transaction est annulée et il est prévenu
        // que son argent lui a été restitué.
        Notification::create([
            'user_id' => $qr->sender_user_id,
            'type'    => 'transaction_update',
            'channel' => 'in_app',
            'title'   => 'QR non retiré — code erroné 3 fois',
            'body'    => sprintf(
                "Le destinataire de votre QR de %s FCFA a échoué 3 fois sur le code de vérification. "
                . 'La transaction a été annulée et le montant a été recrédité sur votre compte.',
                number_format((float) $qr->amount, 0, ',', ' ')
            ),
            'related_transaction_id' => null,
            'read'    => false,
        ]);

        OfflineQrEvent::create([
            'offline_qr_code_id' => $qr->id,
            'event_type'         => OfflineQrEvent::EVENT_CANCELLED_REFUNDED,
            'actor_user_id'      => null,
            'metadata'           => [
                'reason' => 'external_max_attempts_reached',
                'amount' => $qr->amount,
            ],
        ]);

        Log::warning('QR externe annulé après 3 échecs — remboursement effectué', [
            'uuid'   => $qr->uuid,
            'sender' => $qr->sender_user_id,
            'amount' => $qr->amount,
        ]);

        return ['error' => 'MAX_ATTEMPTS_REACHED'];
    }
}
