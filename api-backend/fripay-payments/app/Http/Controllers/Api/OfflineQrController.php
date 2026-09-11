<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OfflineQrCode;
use App\Models\OfflineQrEvent;
use App\Services\QrCryptoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Controller pour les QR Codes d'argent hors-ligne FriPay.
 *
 * @tags QR Codes Hors-ligne
 */
class OfflineQrController extends Controller
{
    public function __construct(
        private QrCryptoService $crypto
    ) {}

    /**
     * Générer un QR Code signé.
     *
     * @bodyParam amount integer required Montant en FCFA. Example: 5000
     * @bodyParam currency string Devise ISO 4217. Défaut: XOF. Example: XOF
     * @bodyParam expires_minutes integer Durée de validité en minutes (5-60). Défaut: 60. Example: 30
     * @bodyParam recipient_hint string|null Indice sur le destinataire (optionnel). Example: +22997000002
     *
     * @response status=201 {"qr_code":"...","uuid":"...","amount":5000,"currency":"XOF","expires_at":"...","status":"active"}
     * @response status=422 {"message":"The given data was invalid."}
     * @response status=401 {"message":"Unauthenticated."}
     *
     * @authenticated
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'          => 'required|integer|min:100|max:500000',
            'currency'        => 'string|max:3',
            'expires_minutes' => 'integer|min:5|max:60',
            'recipient_hint'  => 'nullable|string|max:100',
        ]);

        $userId = $request->user()->getKey();
        $amount = $validated['amount'];
        $currency = $validated['currency'] ?? 'XOF';
        $expiresMinutes = $validated['expires_minutes'] ?? 60;

        $keyPair = $this->crypto->generateKeyPair();
        $expiresAt = now()->addMinutes($expiresMinutes);

        $signed = $this->crypto->createSignedPayload(
            $amount,
            $currency,
            $keyPair['secret_key'],
            $keyPair['public_key'],
            $validated['recipient_hint'] ?? null,
            $expiresAt->toIso8601String()
        );

        $idempotencyKey = Str::random(64);

        $qrCode = DB::transaction(function () use (
            $userId, $amount, $currency, $keyPair, $signed,
            $expiresAt, $idempotencyKey
        ) {
            $qr = OfflineQrCode::create([
                'uuid'              => $signed['uuid'],
                'sender_user_id'    => $userId,
                'amount'            => $amount,
                'currency'          => $currency,
                'sender_public_key' => $this->crypto->publicKeyToBase64($keyPair['public_key']),
                'signature'         => $signed['signature'],
                'qr_payload'        => $signed['qr_content'],
                'status'            => OfflineQrCode::STATUS_ACTIVE,
                'expires_at'        => $expiresAt,
                'idempotency_key'   => $idempotencyKey,
            ]);

            OfflineQrEvent::create([
                'offline_qr_code_id' => $qr->id,
                'event_type'         => OfflineQrEvent::EVENT_GENERATED,
                'actor_user_id'      => $userId,
                'metadata'           => ['amount' => $amount, 'currency' => $currency],
            ]);

            return $qr;
        });

        $this->crypto->wipeKey($keyPair['secret_key']);

        Log::info('QR Code généré', [
            'uuid'   => $signed['uuid'],
            'user'   => $userId,
            'amount' => $amount,
        ]);

        return response()->json([
            'qr_code'    => $signed['qr_content'],
            'uuid'       => $signed['uuid'],
            'amount'     => $amount,
            'currency'   => $currency,
            'expires_at' => $expiresAt->toIso8601String(),
            'status'     => 'active',
        ], 201);
    }

    /**
     * Vérifier la validité d'un QR Code (hors-ligne, public).
     *
     * @bodyParam qr_content string required Le contenu JSON du QR Code scanné.
     *
     * @response status=200 {"valid":true,"status":"active","amount":5000,"currency":"XOF"}
     * @response status=422 {"valid":false,"error":"Signature invalide"}
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_content' => 'required|string|max:10000',
        ]);

        $result = $this->crypto->verifyQrIntegrity($validated['qr_content']);

        if (!$result['valid']) {
            Log::warning('QR Code vérification échouée', [
                'error' => $result['error'],
                'ip'    => $request->ip(),
            ]);

            return response()->json([
                'valid' => false,
                'error' => $result['error'],
                'data'  => $result['data'],
            ], 422);
        }

        $qrCode = OfflineQrCode::where('uuid', $result['data']['uuid'] ?? '')->first();

        $status = 'unknown';
        $error = null;

        if ($qrCode) {
            if (!$qrCode->isActive()) {
                $status = $qrCode->status;
                $error = 'QR Code non actif (statut: ' . $qrCode->status . ')';
            } else {
                $status = 'active';
            }
        } else {
            $status = 'offline_unverified';
        }

        return response()->json([
            'valid'    => $result['valid'],
            'status'   => $status,
            'error'    => $error,
            'data'     => $result['data'],
            'amount'   => $result['data']['amount'] ?? null,
            'currency' => $result['data']['currency'] ?? null,
        ]);
    }

    /**
     * Recevoir un QR Code (avec locking pessimiste).
     *
     * @bodyParam qr_content string required Le contenu JSON du QR Code reçu.
     *
     * @response status=200 {"message":"QR Code reçu et stocké dans votre coffre","uuid":"...","amount":5000,"status":"received"}
     * @response status=404 {"error":"QR_NOT_FOUND"}
     * @response status=422 {"error":"SELF_TRANSFER"}
     *
     * @authenticated
     */
    public function receive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_content' => 'required|string|max:10000',
        ]);

        $result = $this->crypto->verifyQrIntegrity($validated['qr_content']);
        if (!$result['valid']) {
            return response()->json(['error' => 'INVALID_QR', 'message' => $result['error']], 422);
        }

        $uuid = $result['data']['uuid'] ?? '';
        $payloadPubKey = $result['data']['sender_pubkey'] ?? '';
        $userId = $request->user()->getKey();

        // Lock + update atomique dans une transaction
        $qrCode = DB::transaction(function () use ($uuid, $userId, $payloadPubKey) {
            $qrCode = OfflineQrCode::where('uuid', $uuid)
                ->lockForUpdate()
                ->first();

            if (!$qrCode) {
                return ['error' => 'NOT_FOUND'];
            }

            if (!$qrCode->isActive()) {
                return ['error' => 'NOT_ACTIVE'];
            }

            // Rejeter les QR marchand — ils utilisent le flux MerchantQrController
            if ($qrCode->isMerchantQr()) {
                return ['error' => 'MERCHANT_QR'];
            }

            if ($qrCode->sender_user_id === $userId) {
                return ['error' => 'SELF_TRANSFER'];
            }

            // M3 — la clé publique du payload scanné doit correspondre à celle
            // enregistrée pour ce QR à la génération (liée à sender_user_id).
            // Empêche un payload rejoué avec une clé substituée.
            if (!$qrCode->hasPublicKey($payloadPubKey)) {
                return ['error' => 'PUBKEY_MISMATCH'];
            }

            $qrCode->update([
                'status'            => OfflineQrCode::STATUS_RECEIVED,
                'recipient_user_id' => $userId,
                'received_at'       => now(),
            ]);

            OfflineQrEvent::create([
                'offline_qr_code_id' => $qrCode->id,
                'event_type'         => OfflineQrEvent::EVENT_RECEIVED,
                'actor_user_id'      => $userId,
                'metadata'           => ['action' => 'received_offline'],
            ]);

            return ['qr' => $qrCode];
        });

        // Gestion des erreurs hors transaction
        if (isset($qrCode['error'])) {
            Log::warning('QR Code receive échoué', [
                'uuid'  => $uuid,
                'error' => $qrCode['error'],
                'user'  => $userId,
            ]);

            return match ($qrCode['error']) {
                'NOT_FOUND'     => response()->json(['error' => 'QR_NOT_FOUND', 'message' => 'QR Code inconnu'], 404),
                'NOT_ACTIVE'    => response()->json(['error' => 'QR_NOT_ACTIVE', 'message' => 'QR Code non actif'], 422),
                'MERCHANT_QR'   => response()->json(['error' => 'MERCHANT_QR', 'message' => 'Ce QR est un QR marchand'], 422),
                'SELF_TRANSFER' => response()->json([
                    'error'   => 'SELF_TRANSFER',
                    'message' => 'Vous ne pouvez pas recevoir votre propre QR Code',
                ], 422),
                'PUBKEY_MISMATCH' => response()->json([
                    'error'   => 'PUBKEY_MISMATCH',
                    'message' => 'La clé publique du QR ne correspond pas à celle enregistrée',
                ], 422),
                default => response()->json(['error' => 'UNKNOWN'], 500),
            };
        }

        $qr = $qrCode['qr'];

        Log::info('QR Code reçu', [
            'uuid' => $qr->uuid,
            'user' => $userId,
        ]);

        return response()->json([
            'message' => 'QR Code reçu et stocké dans votre coffre',
            'uuid'    => $qr->uuid,
            'amount'  => $qr->amount,
            'status'  => 'received',
        ]);
    }

    /**
     * Encaisser un QR Code (avec locking pessimiste).
     *
     * @bodyParam uuid string required UUID du QR Code à encaisser.
     *
     * @response status=200 {"message":"QR Code encaissé...","uuid":"...","amount":5000,"status":"redeemed"}
     * @response status=404 {"error":"QR_NOT_FOUND"}
     * @response status=422 {"error":"QR_NOT_REDEEMABLE"}
     * @response status=403 {"error":"NOT_OWNER"}
     *
     * @authenticated
     */
    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
        ]);

        $userId = $request->user()->getKey();

        // Lock + update atomique dans une transaction
        $qrCode = DB::transaction(function () use ($validated, $userId) {
            $qrCode = OfflineQrCode::where('uuid', $validated['uuid'])
                ->lockForUpdate()
                ->first();

            if (!$qrCode) {
                return ['error' => 'NOT_FOUND'];
            }

            if (!$qrCode->isRedeemable()) {
                return ['error' => 'NOT_REDEEMABLE'];
            }

            // Rejeter les QR marchand
            if ($qrCode->isMerchantQr()) {
                return ['error' => 'MERCHANT_QR'];
            }

            if ($qrCode->recipient_user_id && $qrCode->recipient_user_id !== $userId) {
                return ['error' => 'NOT_OWNER'];
            }

            $qrCode->update([
                'status'            => OfflineQrCode::STATUS_REDEEMED,
                'redeemed_at'       => now(),
                'recipient_user_id' => $userId,
            ]);

            OfflineQrEvent::create([
                'offline_qr_code_id' => $qrCode->id,
                'event_type'         => OfflineQrEvent::EVENT_REDEEMED,
                'actor_user_id'      => $userId,
                'metadata'           => [
                    'amount'   => $qrCode->amount,
                    'currency' => $qrCode->currency,
                ],
            ]);

            return ['qr' => $qrCode];
        });

        if (isset($qrCode['error'])) {
            Log::warning('QR Code redeem échoué', [
                'uuid'  => $validated['uuid'],
                'error' => $qrCode['error'],
                'user'  => $userId,
            ]);

            return match ($qrCode['error']) {
                'NOT_FOUND'      => response()->json(['error' => 'QR_NOT_FOUND'], 404),
                'NOT_REDEEMABLE' => response()->json([
                    'error'   => 'QR_NOT_REDEEMABLE',
                    'message' => 'Ce QR Code ne peut plus être encaissé',
                ], 422),
                'MERCHANT_QR'    => response()->json(['error' => 'MERCHANT_QR', 'message' => 'Ce QR est un QR marchand'], 422),
                'NOT_OWNER'      => response()->json([
                    'error'   => 'NOT_OWNER',
                    'message' => 'Ce QR Code ne vous appartient pas',
                ], 403),
                default => response()->json(['error' => 'UNKNOWN'], 500),
            };
        }

        $qr = $qrCode['qr'];

        Log::info('QR Code encaissé', [
            'uuid'   => $qr->uuid,
            'amount' => $qr->amount,
            'user'   => $userId,
        ]);

        return response()->json([
            'message' => 'QR Code encaissé. Le settlement sera traité via le connecteur opérateur.',
            'uuid'    => $qr->uuid,
            'amount'  => $qr->amount,
            'status'  => 'redeemed',
        ]);
    }

    /**
     * Transférer un QR Code à un autre utilisateur.
     *
     * @bodyParam uuid string required UUID du QR Code à transférer.
     * @bodyParam recipient_phone string required Numéro de téléphone du destinataire. Example: +22997000002
     *
     * @response status=200 {"message":"QR Code transféré","uuid":"...","amount":5000}
     * @response status=404 {"error":"RECIPIENT_NOT_FOUND"}
     * @response status=403 {"error":"NOT_OWNER"}
     *
     * @authenticated
     */
    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uuid'            => 'required|string',
            'recipient_phone' => 'required|string|min:8|max:15|regex:/^\+[0-9]+$/',
        ]);

        $userId = $request->user()->getKey();

        $qrCode = DB::transaction(function () use ($validated, $userId) {
            $qrCode = OfflineQrCode::where('uuid', $validated['uuid'])
                ->lockForUpdate()
                ->first();

            if (!$qrCode) {
                return ['error' => 'NOT_FOUND'];
            }

            if (!$qrCode->isRedeemable()) {
                return ['error' => 'NOT_TRANSFERABLE'];
            }

            // Rejeter les QR marchand
            if ($qrCode->isMerchantQr()) {
                return ['error' => 'MERCHANT_QR'];
            }

            $currentOwner = $qrCode->recipient_user_id ?? $qrCode->sender_user_id;
            if ($currentOwner !== $userId) {
                return ['error' => 'NOT_OWNER'];
            }

            $recipient = \App\Models\User::where('phone_number', $validated['recipient_phone'])->first();
            if (!$recipient) {
                return ['error' => 'RECIPIENT_NOT_FOUND'];
            }

            $qrCode->update([
                'recipient_user_id' => $recipient->id,
                'received_at'       => now(),
            ]);

            OfflineQrEvent::create([
                'offline_qr_code_id' => $qrCode->id,
                'event_type'         => OfflineQrEvent::EVENT_SCANNED,
                'actor_user_id'      => $userId,
                'metadata'           => [
                    'action' => 'transferred',
                    'from'   => $userId,
                    'to'     => $recipient->id,
                ],
            ]);

            return ['qr' => $qrCode];
        });

        if (isset($qrCode['error'])) {
            return match ($qrCode['error']) {
                'NOT_FOUND'           => response()->json(['error' => 'QR_NOT_FOUND'], 404),
                'NOT_TRANSFERABLE'    => response()->json(['error' => 'QR_NOT_TRANSFERABLE'], 422),
                'MERCHANT_QR'         => response()->json(['error' => 'MERCHANT_QR', 'message' => 'Ce QR est un QR marchand'], 422),
                'NOT_OWNER'           => response()->json(['error' => 'NOT_OWNER'], 403),
                'RECIPIENT_NOT_FOUND' => response()->json(['error' => 'RECIPIENT_NOT_FOUND'], 404),
                default => response()->json(['error' => 'UNKNOWN'], 500),
            };
        }

        return response()->json([
            'message' => 'QR Code transféré',
            'uuid'    => $qrCode['qr']->uuid,
            'amount'  => $qrCode['qr']->amount,
        ]);
    }

    /**
     * Révoquer un QR Code.
     *
     * @bodyParam uuid string required UUID du QR Code à révoquer.
     *
     * @response status=200 {"message":"QR Code révoqué","uuid":"...","refund":5000}
     * @response status=403 {"error":"NOT_SENDER"}
     * @response status=422 {"error":"QR_NOT_REVOCABLE"}
     *
     * @authenticated
     */
    public function revoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
        ]);

        $userId = $request->user()->getKey();

        $qrCode = DB::transaction(function () use ($validated, $userId) {
            $qrCode = OfflineQrCode::where('uuid', $validated['uuid'])
                ->lockForUpdate()
                ->first();

            if (!$qrCode) {
                return ['error' => 'NOT_FOUND'];
            }

            if ($qrCode->sender_user_id !== $userId) {
                return ['error' => 'NOT_SENDER'];
            }

            if (in_array($qrCode->status, [OfflineQrCode::STATUS_REDEEMED, OfflineQrCode::STATUS_REVOKED])) {
                return ['error' => 'NOT_REVOCABLE'];
            }

            $qrCode->update(['status' => OfflineQrCode::STATUS_REVOKED]);

            OfflineQrEvent::create([
                'offline_qr_code_id' => $qrCode->id,
                'event_type'         => OfflineQrEvent::EVENT_REVOKED,
                'actor_user_id'      => $userId,
                'metadata'           => ['refund' => $qrCode->amount],
            ]);

            return ['qr' => $qrCode];
        });

        if (isset($qrCode['error'])) {
            return match ($qrCode['error']) {
                'NOT_FOUND'      => response()->json(['error' => 'QR_NOT_FOUND'], 404),
                'NOT_SENDER'     => response()->json(['error' => 'NOT_SENDER'], 403),
                'NOT_REVOCABLE'  => response()->json(['error' => 'QR_NOT_REVOCABLE'], 422),
                default => response()->json(['error' => 'UNKNOWN'], 500),
            };
        }

        return response()->json([
            'message' => 'QR Code révoqué',
            'uuid'    => $qrCode['qr']->uuid,
            'refund'  => $qrCode['qr']->amount,
        ]);
    }

    /**
     * Lister mes QR Codes actifs (envoyés, non expirés, non consommés).
     *
     * @response status=200 {"data":[{"uuid":"...","amount":5000,"currency":"XOF","expires_at":"..."}]}
     *
     * @authenticated
     */
    public function mine(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();

        $qrCodes = OfflineQrCode::query()
            ->active()
            ->where('sender_user_id', $userId)
            ->orderByDesc('created_at')
            ->get(['uuid', 'amount', 'currency', 'status', 'qr_mode', 'qr_type', 'expires_at', 'created_at']);

        return response()->json(['data' => $qrCodes]);
    }

    /**
     * Obtenir le statut d'un QR Code.
     * Seul l'expéditeur, le récepteur ou le marchand peut consulter les détails.
     *
     * @pathParam uuid string UUID du QR Code.
     *
     * @response status=200 {"uuid":"...","amount":5000,"status":"active","events":[...]}
     * @response status=404 {"error":"QR_NOT_FOUND"}
     * @response status=403 {"error":"NOT_AUTHORIZED"}
     *
     * @authenticated
     */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $qrCode = OfflineQrCode::where('uuid', $uuid)->with('events')->first();

        if (!$qrCode) {
            return response()->json(['error' => 'QR_NOT_FOUND'], 404);
        }

        // Vérifier que l'utilisateur est l'expéditeur, le récepteur ou le marchand
        $userId = $request->user()->getKey();
        if (
            $qrCode->sender_user_id !== $userId
            && $qrCode->recipient_user_id !== $userId
            && $qrCode->merchant_user_id !== $userId
        ) {
            return response()->json(['error' => 'NOT_AUTHORIZED', 'message' => 'Accès non autorisé à ce QR Code'], 403);
        }

        return response()->json([
            'uuid'        => $qrCode->uuid,
            'amount'      => $qrCode->amount,
            'currency'    => $qrCode->currency,
            'status'      => $qrCode->status,
            'qr_mode'     => $qrCode->qr_mode,
            'qr_type'     => $qrCode->qr_type,
            'description' => $qrCode->description,
            'created_at'  => $qrCode->created_at->toIso8601String(),
            'expires_at'  => $qrCode->expires_at->toIso8601String(),
            'received_at' => $qrCode->received_at?->toIso8601String(),
            'redeemed_at' => $qrCode->redeemed_at?->toIso8601String(),
            'events'      => $qrCode->events->map(fn($e) => [
                'type'      => $e->event_type,
                'actor_id'  => $e->actor_user_id,
                'timestamp' => $e->created_at->toIso8601String(),
                'metadata'  => $e->metadata,
            ]),
        ]);
    }
}
