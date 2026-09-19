<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Corridor;
use App\Models\OfflineQrCode;
use App\Models\OfflineQrEvent;
use App\Models\Transaction;
use App\Models\TransactionStatusHistory;
use App\Services\OperatorDetectionService;
use App\Services\QrCryptoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Contrôleur pour les paiements QR Code marchand (CPM / MPM).
 *
 * Deux modes de paiement :
 * - MPM (Merchant Present Mode) : le client scanne le QR du marchand
 * - CPM (Customer Present Mode)  : le marchand scanne le QR du client
 *
 * Deux types de QR :
 * - Static  : identité du marchand, montant saisi par le payeur
 * - Dynamic : montant pré-rempli, expire automatiquement
 *
 * @tags QR Payments
 */
class MerchantQrController extends Controller
{
    public function __construct(
        private QrCryptoService $crypto,
        private OperatorDetectionService $operatorDetection,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    //  MPM — Merchant Present Mode (client scanne le marchand)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Générer un QR Code marchand (MPM).
     *
     * Le marchand génère un QR code (statique ou dynamique) qu'il affiche
     * sur son écran ou imprime. Le client le scannera ensuite.
     *
     * @bodyParam qr_type string required 'static' ou 'dynamic'. Example: dynamic
     * @bodyParam amount integer Montant en FCFA (requis pour dynamic). Example: 5000
     * @bodyParam currency string Devise ISO 4217. Défaut: XOF. Example: XOF
     * @bodyParam description string Description du paiement. Example: Achat boutique #1234
     * @bodyParam expires_minutes integer Durée de validité en minutes (5-60). Défaut: 30. Example: 15
     * @bodyParam single_use boolean QR à usage unique (détruit après 1er paiement). Défaut: false
     *
     * @response status=201 {"qr_code":"...","uuid":"...","qr_type":"dynamic","qr_mode":"mpm","amount":5000,"currency":"XOF","expires_at":"..."}
     *
     * @authenticated
     */
    public function generateMpm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_type'         => 'required|string|in:static,dynamic',
            'amount'          => 'required_if:qr_type,dynamic|nullable|integer|min:100|max:500000',
            'currency'        => 'string|max:3',
            'description'     => 'nullable|string|max:255',
            'expires_minutes' => 'integer|min:5|max:60',
            'single_use'      => 'boolean',
        ]);

        $userId  = $request->user()->getKey();
        $qrType  = $validated['qr_type'];
        $amount  = $validated['amount'] ?? 0;
        $currency = $validated['currency'] ?? 'XOF';
        $expiresMinutes = $validated['expires_minutes'] ?? 30;
        $singleUse = $validated['single_use'] ?? false;

        $keyPair  = $this->crypto->generateKeyPair();
        $expiresAt = now()->addMinutes($expiresMinutes);

        // Créer le payload signé selon le type
        if ($qrType === 'static') {
            $signed = $this->crypto->createStaticPayload(
                $currency,
                $keyPair['secret_key'],
                $keyPair['public_key'],
                null,
                $expiresAt->toIso8601String(),
                'mpm',
                $validated['description'] ?? null,
            );
        } else {
            $signed = $this->crypto->createSignedPayload(
                $amount,
                $currency,
                $keyPair['secret_key'],
                $keyPair['public_key'],
                null,
                $expiresAt->toIso8601String(),
                'mpm',
                $validated['description'] ?? null,
            );
        }

        $idempotencyKey = Str::random(64);

        $qrCode = DB::transaction(function () use (
            $userId, $amount, $currency, $keyPair, $signed,
            $expiresAt, $idempotencyKey, $qrType, $singleUse, $validated
        ) {
            $qr = OfflineQrCode::create([
                'uuid'              => $signed['uuid'],
                'sender_user_id'    => $userId,
                'merchant_user_id'  => $userId,
                'amount'            => $amount,
                'currency'          => $currency,
                'sender_public_key' => $this->crypto->publicKeyToBase64($keyPair['public_key']),
                'signature'         => $signed['signature'],
                'qr_payload'        => $signed['qr_content'],
                'qr_mode'           => OfflineQrCode::MODE_MPM,
                'qr_type'           => $qrType,
                'description'       => $validated['description'] ?? null,
                'single_use'        => $singleUse,
                'status'            => OfflineQrCode::STATUS_ACTIVE,
                'expires_at'        => $expiresAt,
                'idempotency_key'   => $idempotencyKey,
            ]);

            OfflineQrEvent::create([
                'offline_qr_code_id' => $qr->id,
                'event_type'         => OfflineQrEvent::EVENT_GENERATED,
                'actor_user_id'      => $userId,
                'metadata'           => [
                    'amount'     => $amount,
                    'currency'   => $currency,
                    'qr_type'    => $qrType,
                    'qr_mode'    => 'mpm',
                    'single_use' => $singleUse,
                ],
            ]);

            return $qr;
        });

        $this->crypto->wipeKey($keyPair['secret_key']);

        Log::info('QR Marchand MPM généré', [
            'uuid'   => $signed['uuid'],
            'user'   => $userId,
            'amount' => $amount,
            'type'   => $qrType,
        ]);

        return response()->json([
            'qr_code'    => $signed['qr_content'],
            'uuid'       => $signed['uuid'],
            'qr_type'    => $qrType,
            'qr_mode'    => 'mpm',
            'amount'     => $qrType === 'dynamic' ? $amount : null,
            'currency'   => $currency,
            'description' => $validated['description'] ?? null,
            'single_use' => $singleUse,
            'expires_at' => $expiresAt->toIso8601String(),
            'status'     => 'active',
        ], 201);
    }

    /**
     * Scanner un QR Code marchand (MPM) — côté client.
     *
     * Le client scanne le QR du marchand, vérifie sa validité cryptographique,
     * et reçoit les détails pour confirmer le paiement.
     *
     * @bodyParam qr_content string required Le contenu JSON du QR Code scanné.
     * @bodyParam amount integer Montant saisi (requis pour QR statique). Example: 2500
     *
     * @response status=200 {"valid":true,"uuid":"...","qr_type":"dynamic","amount":5000,"currency":"XOF","merchant_name":"Boutique #1"}
     * @response status=422 {"valid":false,"error":"..."}
     *
     * @authenticated
     */
    public function scanMpm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_content' => 'required|string|max:10000',
            'amount'     => 'nullable|integer|min:100|max:500000',
        ]);

        // 1. Vérifier l'intégrité cryptographique
        $result = $this->crypto->verifyQrIntegrity($validated['qr_content']);

        if (!$result['valid']) {
            Log::warning('QR Marchand scan échoué', [
                'error' => $result['error'],
                'ip'    => $request->ip(),
            ]);

            return response()->json([
                'valid' => false,
                'error' => $result['error'],
                'data'  => $result['data'],
            ], 422);
        }

        $payload = $result['data'];
        $uuid    = $payload['uuid'] ?? '';
        $qrType  = $payload['type'] ?? 'dynamic';

        // 2. Vérifier que le QR existe en base et est actif
        $qrCode = OfflineQrCode::where('uuid', $uuid)->first();

        if (!$qrCode) {
            return response()->json([
                'valid' => false,
                'error' => 'QR Code inconnu dans le système',
            ], 422);
        }

        if (!$qrCode->isActive()) {
            return response()->json([
                'valid' => false,
                'error' => 'QR Code non actif (statut: ' . $qrCode->status . ')',
            ], 422);
        }

        // Vérifier que c'est bien un QR MPM
        if ($qrCode->qr_mode !== OfflineQrCode::MODE_MPM) {
            return response()->json([
                'valid' => false,
                'error' => 'Ce QR Code n\'est pas un QR MPM',
            ], 422);
        }

        // 3. Pour un QR statique, le montant doit être fourni
        if ($qrType === 'static') {
            if (!isset($validated['amount']) || $validated['amount'] <= 0) {
                return response()->json([
                    'valid'          => true,
                    'uuid'           => $uuid,
                    'qr_type'        => 'static',
                    'amount_required'=> true,
                    'currency'       => $qrCode->currency,
                    'description'    => $qrCode->description,
                    'message'        => 'Montant requis pour un QR Code statique',
                ]);
            }
            $amount = $validated['amount'];
        } else {
            $amount = $qrCode->amount;
        }

        // 4. Récupérer les infos du marchand
        $merchant = $qrCode->merchant;

        Log::info('QR Marchand MPM scanné', [
            'uuid'     => $uuid,
            'customer' => $request->user()->getKey(),
            'amount'   => $amount,
        ]);

        return response()->json([
            'valid'         => true,
            'uuid'          => $uuid,
            'qr_type'       => $qrType,
            'qr_mode'       => 'mpm',
            'amount'        => $amount,
            'currency'      => $qrCode->currency,
            'description'   => $qrCode->description,
            'merchant_name' => $merchant
                ? trim(($merchant->first_name ?? '') . ' ' . ($merchant->last_name ?? ''))
                : null,
            'expires_at'    => $qrCode->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Confirmer et payer un QR Code marchand (MPM) — côté client.
     *
     * La transaction est créée ATOMIQUEMENT dans la même DB transaction
     * que la validation du QR pour éviter les race conditions.
     *
     * @bodyParam uuid string required UUID du QR Code.
     * @bodyParam amount integer required Montant à payer (doit correspondre au QR).
     * @bodyParam pin string required Code PIN du client (4-6 chiffres). Example: 1234
     * @bodyParam sender_account_id string required ID du compte mobile money du client.
     *
     * @response status=202 {"transaction_id":"...","reference":"QR-...","status":"pending","amount":5000,"fee_amount":50,"total_debited":5050}
     *
     * @authenticated
     */
    public function payMpm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uuid'              => 'required|string',
            'amount'            => 'required|integer|min:100|max:500000',
            'pin'               => 'required|string|min:4|max:6',
            'sender_account_id' => 'required|string',
        ]);

        $userId = $request->user()->getKey();
        $uuid   = $validated['uuid'];

        // Lock + vérification + création transaction — TOUT dans une seule DB transaction
        $result = DB::transaction(function () use ($uuid, $userId, $validated) {
            $qrCode = OfflineQrCode::where('uuid', $uuid)
                ->lockForUpdate()
                ->first();

            if (!$qrCode) {
                return ['error' => 'NOT_FOUND'];
            }

            if (!$qrCode->isPayable()) {
                return ['error' => 'NOT_PAYABLE'];
            }

            // Vérifier que c'est bien un QR MPM
            if ($qrCode->qr_mode !== OfflineQrCode::MODE_MPM) {
                return ['error' => 'NOT_MPM'];
            }

            // Le payeur ne peut pas payer son propre QR
            if ($qrCode->sender_user_id === $userId) {
                return ['error' => 'SELF_PAYMENT'];
            }

            // Vérifier le montant (pour QR dynamique, doit correspondre)
            $amount = (int) $validated['amount'];
            if ($qrCode->isDynamic() && $qrCode->amount !== $amount) {
                return ['error' => 'AMOUNT_MISMATCH'];
            }

            // Vérifier le PIN
            if (!$this->verifyPin($userId, $validated['pin'])) {
                return ['error' => 'INVALID_PIN'];
            }

            // ── Calculer les frais via le corridor ──────────────────
            $merchantPhone = $qrCode->merchant?->phone_number ?? '';
            $feeAmount = 0;
            $totalDebited = $amount;

            if ($merchantPhone) {
                $recipientOperator = $this->operatorDetection->detect($merchantPhone);
                if ($recipientOperator) {
                    $corridor = Corridor::where('destination_operator_id', $recipientOperator->id)
                        ->where('active', true)
                        ->orderBy('priority')
                        ->first();

                    if ($corridor) {
                        $feeAmount = $this->calculateFee($corridor, $amount);
                        $totalDebited = $amount + $feeAmount;

                        // Valider les bornes du montant via le corridor
                        if ($amount < $corridor->min_amount || $amount > $corridor->max_amount) {
                            return ['error' => 'AMOUNT_OUT_OF_RANGE'];
                        }
                    }
                }
            }

            // ── Incrémenter le compteur ─────────────────────────────
            $qrCode->increment('use_count');

            // ── Marquer le QR comme utilisé si single_use ───────────
            if ($qrCode->single_use) {
                $qrCode->update(['status' => OfflineQrCode::STATUS_REDEEMED]);
            }

            // ── Enregistrer l'événement ─────────────────────────────
            OfflineQrEvent::create([
                'offline_qr_code_id' => $qrCode->id,
                'event_type'         => OfflineQrEvent::EVENT_REDEEMED,
                'actor_user_id'      => $userId,
                'metadata'           => [
                    'amount'   => $amount,
                    'currency' => $qrCode->currency,
                    'mode'     => 'mpm',
                    'type'     => $qrCode->qr_type,
                    'fee'      => $feeAmount,
                ],
            ]);

            // ── Créer la transaction (ATOMIQUE) ────────────────────
            $reference = 'QR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

            $transaction = Transaction::create([
                'reference'             => $reference,
                'idempotency_key'       => Str::uuid()->toString(),
                'sender_user_id'        => $userId,
                'sender_account_id'     => $validated['sender_account_id'],
                'recipient_phone'       => $merchantPhone,
                'amount'                => $amount,
                'currency'              => $qrCode->currency,
                'fee_amount'            => $feeAmount,
                'total_debited'         => $totalDebited,
                'rail_used'             => 'qr_mpm',
                'aggregator_provider'   => null,
                'corridor_id'           => null,
                'status'                => 'pending',
                'client_type_snapshot'  => 'qr_payment',
                'initiated_at'          => now(),
                'metadata'              => [
                    'qr_uuid'     => $qrCode->uuid,
                    'qr_mode'     => 'mpm',
                    'qr_type'     => $qrCode->qr_type,
                    'merchant_id' => $qrCode->merchant_user_id,
                ],
            ]);

            TransactionStatusHistory::create([
                'transaction_id'   => $transaction->id,
                'previous_status'  => null,
                'new_status'       => 'pending',
                'source'           => 'qr_payment',
                'note'             => 'Paiement QR MPM initié',
            ]);

            return [
                'success'     => true,
                'transaction' => $transaction,
                'amount'      => $amount,
                'fee_amount'  => $feeAmount,
                'total'       => $totalDebited,
            ];
        });

        if (isset($result['error'])) {
            Log::warning('QR MPM paiement échoué', [
                'uuid'  => $uuid,
                'error' => $result['error'],
                'user'  => $userId,
            ]);

            return match ($result['error']) {
                'NOT_FOUND'       => response()->json(['error' => 'QR_NOT_FOUND', 'message' => 'QR Code inconnu'], 404),
                'NOT_PAYABLE'     => response()->json(['error' => 'QR_NOT_PAYABLE', 'message' => 'QR Code non payable'], 422),
                'NOT_MPM'         => response()->json(['error' => 'NOT_MPM', 'message' => 'Ce QR n\'est pas un QR MPM'], 422),
                'SELF_PAYMENT'    => response()->json(['error' => 'SELF_PAYMENT', 'message' => 'Vous ne pouvez pas payer votre propre QR Code'], 422),
                'AMOUNT_MISMATCH' => response()->json(['error' => 'AMOUNT_MISMATCH', 'message' => 'Le montant ne correspond pas au QR Code'], 422),
                'AMOUNT_OUT_OF_RANGE' => response()->json(['error' => 'AMOUNT_OUT_OF_RANGE', 'message' => 'Montant hors limites du corridor'], 422),
                'INVALID_PIN'     => response()->json(['error' => 'INVALID_PIN', 'message' => 'Code PIN incorrect'], 401),
                default           => response()->json(['error' => 'UNKNOWN'], 500),
            };
        }

        $transaction = $result['transaction'];

        Log::info('QR MPM paiement initié', [
            'uuid'        => $uuid,
            'transaction' => $transaction->id,
            'amount'      => $result['amount'],
            'fee'         => $result['fee_amount'],
            'customer'    => $userId,
            'merchant'    => $transaction->recipient_phone,
        ]);

        return response()->json([
            'message'        => 'Paiement initié. Confirmation en attente.',
            'transaction_id' => $transaction->id,
            'reference'      => $transaction->reference,
            'amount'         => $result['amount'],
            'fee_amount'     => $result['fee_amount'],
            'total_debited'  => $result['total'],
            'currency'       => $transaction->currency,
            'status'         => 'pending',
        ], 202);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CPM — Customer Present Mode (marchand scanne le client)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Générer un QR Code client (CPM).
     *
     * Le client génère un QR code sur son téléphone que le marchand
     * pourra scanner pour prélever le montant.
     *
     * @bodyParam amount integer required Montant en FCFA. Example: 3000
     * @bodyParam currency string Devise ISO 4217. Défaut: XOF. Example: XOF
     * @bodyParam description string Description du paiement. Example: Café au lait
     * @bodyParam expires_minutes integer Durée de validité en minutes (1-10). Défaut: 5. Example: 3
     * @bodyParam sender_account_id string required ID du compte mobile money à débiter. Example: uuid
     *
     * @response status=201 {"qr_code":"...","uuid":"...","amount":3000,"expires_at":"..."}
     *
     * @authenticated
     */
    public function generateCpm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'            => 'required|integer|min:100|max:500000',
            'currency'          => 'string|max:3',
            'description'       => 'nullable|string|max:255',
            'expires_minutes'   => 'integer|min:1|max:10',
            'sender_account_id' => 'required|string',
        ]);

        $userId  = $request->user()->getKey();
        $amount  = $validated['amount'];
        $currency = $validated['currency'] ?? 'XOF';
        $expiresMinutes = $validated['expires_minutes'] ?? 5;

        $keyPair  = $this->crypto->generateKeyPair();
        $expiresAt = now()->addMinutes($expiresMinutes);

        $signed = $this->crypto->createSignedPayload(
            $amount,
            $currency,
            $keyPair['secret_key'],
            $keyPair['public_key'],
            null,
            $expiresAt->toIso8601String(),
            'cpm',
            $validated['description'] ?? null,
        );

        $idempotencyKey = Str::random(64);

        $qrCode = DB::transaction(function () use (
            $userId, $amount, $currency, $keyPair, $signed,
            $expiresAt, $idempotencyKey, $validated
        ) {
            $qr = OfflineQrCode::create([
                'uuid'              => $signed['uuid'],
                'sender_user_id'    => $userId,
                'amount'            => $amount,
                'currency'          => $currency,
                'sender_public_key' => $this->crypto->publicKeyToBase64($keyPair['public_key']),
                'signature'         => $signed['signature'],
                'qr_payload'        => $signed['qr_content'],
                'qr_mode'           => OfflineQrCode::MODE_CPM,
                'qr_type'           => OfflineQrCode::TYPE_DYNAMIC,
                'description'       => $validated['description'] ?? null,
                'single_use'        => true,
                'status'            => OfflineQrCode::STATUS_ACTIVE,
                'expires_at'        => $expiresAt,
                'idempotency_key'   => $idempotencyKey,
                'metadata'          => [
                    'sender_account_id' => $validated['sender_account_id'],
                ],
            ]);

            OfflineQrEvent::create([
                'offline_qr_code_id' => $qr->id,
                'event_type'         => OfflineQrEvent::EVENT_GENERATED,
                'actor_user_id'      => $userId,
                'metadata'           => [
                    'amount'   => $amount,
                    'currency' => $currency,
                    'qr_type'  => 'dynamic',
                    'qr_mode'  => 'cpm',
                ],
            ]);

            return $qr;
        });

        $this->crypto->wipeKey($keyPair['secret_key']);

        Log::info('QR Client CPM généré', [
            'uuid'   => $signed['uuid'],
            'user'   => $userId,
            'amount' => $amount,
        ]);

        return response()->json([
            'qr_code'     => $signed['qr_content'],
            'uuid'        => $signed['uuid'],
            'qr_type'     => 'dynamic',
            'qr_mode'     => 'cpm',
            'amount'      => $amount,
            'currency'    => $currency,
            'description' => $validated['description'] ?? null,
            'expires_at'  => $expiresAt->toIso8601String(),
            'status'      => 'active',
        ], 201);
    }

    /**
     * Scanner un QR Code client (CPM) — côté marchand.
     *
     * Le marchand scanne le QR du client, vérifie la validité,
     * et prépare la transaction.
     *
     * @bodyParam qr_content string required Le contenu JSON du QR Code scanné.
     *
     * @response status=200 {"valid":true,"uuid":"...","amount":3000,"currency":"XOF","customer_name":"Jean"}
     *
     * @authenticated
     */
    public function scanCpm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_content' => 'required|string|max:10000',
        ]);

        $result = $this->crypto->verifyQrIntegrity($validated['qr_content']);

        if (!$result['valid']) {
            Log::warning('QR Client scan échoué', [
                'error' => $result['error'],
                'ip'    => $request->ip(),
            ]);

            return response()->json([
                'valid' => false,
                'error' => $result['error'],
                'data'  => $result['data'],
            ], 422);
        }

        $payload = $result['data'];
        $uuid    = $payload['uuid'] ?? '';

        $qrCode = OfflineQrCode::where('uuid', $uuid)->first();

        if (!$qrCode) {
            return response()->json([
                'valid' => false,
                'error' => 'QR Code inconnu dans le système',
            ], 422);
        }

        if (!$qrCode->isActive()) {
            return response()->json([
                'valid' => false,
                'error' => 'QR Code non actif (statut: ' . $qrCode->status . ')',
            ], 422);
        }

        if ($qrCode->qr_mode !== OfflineQrCode::MODE_CPM) {
            return response()->json([
                'valid' => false,
                'error' => 'Ce QR Code n\'est pas un QR CPM',
            ], 422);
        }

        $customer = $qrCode->sender;

        Log::info('QR Client CPM scanné', [
            'uuid'     => $uuid,
            'merchant' => $request->user()->getKey(),
            'amount'   => $qrCode->amount,
        ]);

        return response()->json([
            'valid'         => true,
            'uuid'          => $uuid,
            'qr_type'       => 'dynamic',
            'qr_mode'       => 'cpm',
            'amount'        => $qrCode->amount,
            'currency'      => $qrCode->currency,
            'description'   => $qrCode->description,
            'customer_name' => $customer
                ? trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
                : null,
            'expires_at'    => $qrCode->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Encaisser un QR Code client (CPM) — côté marchand.
     *
     * Le marchand confirme l'encaissement avec son PIN. La transaction est
     * créée ATOMIQUEMENT dans la même DB transaction.
     *
     * Le sender_account_id est résolu depuis les métadonnées du QR (fourni
     * par le client lors de la génération).
     *
     * @bodyParam uuid string required UUID du QR Code.
     * @bodyParam merchant_account_id string required ID du compte mobile money du marchand.
     * @bodyParam pin string required Code PIN du marchand. Example: 5678
     *
     * @response status=202 {"transaction_id":"...","reference":"QR-...","status":"pending","amount":3000,"fee_amount":30,"total_debited":3030}
     *
     * @authenticated
     */
    public function chargeCpm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uuid'                => 'required|string',
            'merchant_account_id' => 'required|string',
            'pin'                 => 'required|string|min:4|max:6',
        ]);

        $merchantId = $request->user()->getKey();
        $uuid       = $validated['uuid'];

        $result = DB::transaction(function () use ($uuid, $merchantId, $validated) {
            $qrCode = OfflineQrCode::where('uuid', $uuid)
                ->lockForUpdate()
                ->first();

            if (!$qrCode) {
                return ['error' => 'NOT_FOUND'];
            }

            if (!$qrCode->isPayable()) {
                return ['error' => 'NOT_PAYABLE'];
            }

            if ($qrCode->qr_mode !== OfflineQrCode::MODE_CPM) {
                return ['error' => 'NOT_CPM'];
            }

            // Le propriétaire du QR ne peut pas l'encaisser
            if ($qrCode->sender_user_id === $merchantId) {
                return ['error' => 'SELF_CHARGE'];
            }

            // Vérifier le PIN du marchand
            if (!$this->verifyPin($merchantId, $validated['pin'])) {
                return ['error' => 'INVALID_PIN'];
            }

            // ── Résoudre le sender_account_id depuis les métadonnées ────
            $senderAccountId = $qrCode->metadata['sender_account_id'] ?? null;

            if (!$senderAccountId) {
                return ['error' => 'MISSING_SENDER_ACCOUNT'];
            }

            // ── Calculer les frais ──────────────────────────────────
            $amount = $qrCode->amount;
            $feeAmount = 0;
            $totalDebited = $amount;

            // Le client est le sender_user_id, résoudre son opérateur
            $customerPhone = $qrCode->sender?->phone_number ?? '';
            if ($customerPhone) {
                $recipientOperator = $this->operatorDetection->detect($customerPhone);
                if ($recipientOperator) {
                    $corridor = Corridor::where('destination_operator_id', $recipientOperator->id)
                        ->where('active', true)
                        ->orderBy('priority')
                        ->first();

                    if ($corridor) {
                        $feeAmount = $this->calculateFee($corridor, $amount);
                        $totalDebited = $amount + $feeAmount;
                    }
                }
            }

            // ── Incrémenter et marquer usage unique ─────────────────
            $qrCode->increment('use_count');

            $qrCode->update([
                'status'            => OfflineQrCode::STATUS_REDEEMED,
                'redeemed_at'       => now(),
                'recipient_user_id' => $merchantId,
            ]);

            OfflineQrEvent::create([
                'offline_qr_code_id' => $qrCode->id,
                'event_type'         => OfflineQrEvent::EVENT_REDEEMED,
                'actor_user_id'      => $merchantId,
                'metadata'           => [
                    'amount' => $amount,
                    'currency' => $qrCode->currency,
                    'mode'   => 'cpm',
                    'fee'    => $feeAmount,
                ],
            ]);

            // ── Créer la transaction (ATOMIQUE) ────────────────────
            $reference = 'QR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

            $transaction = Transaction::create([
                'reference'             => $reference,
                'idempotency_key'       => Str::uuid()->toString(),
                'sender_user_id'        => $qrCode->sender_user_id,
                'sender_account_id'     => $senderAccountId,
                'recipient_phone'       => $request->user()->phone_number ?? '',
                'amount'                => $amount,
                'currency'              => $qrCode->currency,
                'fee_amount'            => $feeAmount,
                'total_debited'         => $totalDebited,
                'rail_used'             => 'qr_cpm',
                'aggregator_provider'   => null,
                'corridor_id'           => null,
                'status'                => 'pending',
                'client_type_snapshot'  => 'qr_payment',
                'initiated_at'          => now(),
                'metadata'              => [
                    'qr_uuid'     => $qrCode->uuid,
                    'qr_mode'     => 'cpm',
                    'merchant_id' => $merchantId,
                ],
            ]);

            TransactionStatusHistory::create([
                'transaction_id'   => $transaction->id,
                'previous_status'  => null,
                'new_status'       => 'pending',
                'source'           => 'qr_payment',
                'note'             => 'Paiement QR CPM initié par le marchand',
            ]);

            return [
                'success'     => true,
                'transaction' => $transaction,
                'amount'      => $amount,
                'fee_amount'  => $feeAmount,
                'total'       => $totalDebited,
            ];
        });

        if (isset($result['error'])) {
            Log::warning('QR CPM encaissement échoué', [
                'uuid'  => $uuid,
                'error' => $result['error'],
                'user'  => $merchantId,
            ]);

            return match ($result['error']) {
                'NOT_FOUND'           => response()->json(['error' => 'QR_NOT_FOUND', 'message' => 'QR Code inconnu'], 404),
                'NOT_PAYABLE'         => response()->json(['error' => 'QR_NOT_PAYABLE', 'message' => 'QR Code non payable'], 422),
                'NOT_CPM'             => response()->json(['error' => 'NOT_CPM', 'message' => 'Ce QR n\'est pas un QR CPM'], 422),
                'SELF_CHARGE'         => response()->json(['error' => 'SELF_CHARGE', 'message' => 'Vous ne pouvez pas encaisser votre propre QR'], 422),
                'INVALID_PIN'         => response()->json(['error' => 'INVALID_PIN', 'message' => 'Code PIN incorrect'], 401),
                'MISSING_SENDER_ACCOUNT' => response()->json(['error' => 'MISSING_SENDER_ACCOUNT', 'message' => 'Le compte du client n\'est pas défini dans le QR'], 422),
                default               => response()->json(['error' => 'UNKNOWN'], 500),
            };
        }

        $transaction = $result['transaction'];

        Log::info('QR CPM encaissement initié', [
            'uuid'        => $uuid,
            'transaction' => $transaction->id,
            'amount'      => $result['amount'],
            'fee'         => $result['fee_amount'],
            'customer'    => $transaction->sender_user_id,
            'merchant'    => $merchantId,
        ]);

        return response()->json([
            'message'        => 'Encaissement initié. Le client sera débité.',
            'transaction_id' => $transaction->id,
            'reference'      => $transaction->reference,
            'amount'         => $result['amount'],
            'fee_amount'     => $result['fee_amount'],
            'total_debited'  => $result['total'],
            'currency'       => $transaction->currency,
            'status'         => 'pending',
        ], 202);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Communs
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Historique des QR Codes d'un marchand.
     *
     * @queryParam page int Numéro de page. Example: 1
     * @queryParam size int Éléments par page (max 50). Example: 20
     * @queryParam qr_type string Filtrer par type (static/dynamic).
     * @queryParam qr_mode string Filtrer par mode (cpm/mpm).
     * @queryParam status string Filtrer par statut.
     *
     * @response status=200 {"data":[...],"meta":{"page":1,"size":20,"total":42}}
     *
     * @authenticated
     */
    public function history(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();

        $query = OfflineQrCode::where('merchant_user_id', $userId)
            ->orderBy('created_at', 'desc');

        if ($request->filled('qr_type')) {
            $query->where('qr_type', $request->qr_type);
        }

        if ($request->filled('qr_mode')) {
            $query->where('qr_mode', $request->qr_mode);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) $request->get('size', 20), 50);
        $qrCodes = $query->paginate($perPage);

        $data = collect($qrCodes->items())->map(fn ($qr) => [
            'uuid'        => $qr->uuid,
            'qr_type'     => $qr->qr_type,
            'qr_mode'     => $qr->qr_mode,
            'amount'      => $qr->amount,
            'currency'    => $qr->currency,
            'description' => $qr->description,
            'single_use'  => $qr->single_use,
            'use_count'   => $qr->use_count,
            'status'      => $qr->status,
            'created_at'  => $qr->created_at->toIso8601String(),
            'expires_at'  => $qr->expires_at->toIso8601String(),
            'redeemed_at' => $qr->redeemed_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'page'     => $qrCodes->currentPage(),
                'size'     => $qrCodes->perPage(),
                'total'    => $qrCodes->total(),
                'has_next' => $qrCodes->hasMorePages(),
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Private Helpers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Vérifie le PIN d'un utilisateur.
     */
    private function verifyPin(string $userId, string $pin): bool
    {
        $user = \App\Models\User::find($userId);

        if (!$user || !$user->pin_hash) {
            return false;
        }

        return password_verify($pin, $user->pin_hash);
    }

    /**
     * Calcule les frais selon la règle du corridor (fixed | percentage | tiered).
     */
    private function calculateFee(Corridor $corridor, float $amount): float
    {
        $fee = match ($corridor->fee_type) {
            'fixed'      => (float) $corridor->fee_value,
            'percentage' => $amount * ((float) $corridor->fee_value / 100),
            'tiered'     => (float) $corridor->fee_value,
            default      => 0.0,
        };

        if ($corridor->fee_cap && $fee > (float) $corridor->fee_cap) {
            $fee = (float) $corridor->fee_cap;
        }

        return round($fee, 2);
    }
}
