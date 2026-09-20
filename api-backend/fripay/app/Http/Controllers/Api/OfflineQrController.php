<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OfflineQrCode;
use App\Models\OfflineQrEvent;
use App\Models\PendingTransfer;
use App\Rules\RecipientPhone;
use App\Models\User;
use App\Services\QrCryptoService;
use App\Services\WalletService;
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
        private QrCryptoService $crypto,
        private WalletService $wallets
    ) {}

    /**
     * Retrouve un utilisateur par numéro Fripay (30 + 8 chiffres) ou par
     * numéro opérateur, quel que soit celui des deux fourni.
     */
    private function findUserByAnyNumber(string $value): ?User
    {
        $digits = preg_replace('/\D/', '', $value);

        if (preg_match('/^30\d{8}$/', $digits)) {
            return User::where('fripay_number', $digits)->first();
        }

        return User::where('phone_number', $value)->first()
            ?? User::where('phone_number', $digits)->first();
    }

    /**
     * Code de validation à 5 chiffres pour le parcours receveur externe.
     */
    private function generateExternalCode(): string
    {
        return str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Générer un QR Code signé.
     *
     * @bodyParam amount integer required Montant en FCFA. Example: 5000
     * @bodyParam currency string Devise ISO 4217. Défaut: XOF. Example: XOF
     * @bodyParam expires_minutes integer Durée de validité en minutes (5-43200). Facultatif — par défaut le QR n'expire JAMAIS (§6.b : retrait possible même 1 an plus tard). Example: 30
     * @bodyParam recipient_hint string|null Indice sur le destinataire (optionnel). Example: +22997000002
     * @bodyParam external_validation_code string|null Code de vérification à 5 chiffres DÉFINI PAR L'ENVOYEUR (cahier des charges : c'est lui qui le choisit et le transmet lui-même au receveur). Requis si recipient_phone est fourni sans compte Fripay. Example: 04821
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
            // §6.b : pas de délai imposé — expiration facultative désormais.
            'expires_minutes' => 'nullable|integer|min:5|max:43200',
            'recipient_hint'  => 'nullable|string|max:100',
            // §6.a/e : numéro du receveur, utilisé pour détecter son compte.
            'recipient_phone' => ['nullable', 'string', 'max:20', new RecipientPhone],
            // Cahier des charges : le code de vérification est DÉFINI PAR
            // L'ENVOYEUR (5 chiffres), pas généré par l'appli.
            'external_validation_code' => ['nullable', 'string', 'regex:/^\d{5}$/'],
        ], [
            'external_validation_code.regex' => 'Le code de vérification doit être exactement 5 chiffres.',
        ]);

        $recipientPhone = $validated['recipient_phone'] ?? null;
        $senderValidationCode = isset($validated['external_validation_code'])
            ? $validated['external_validation_code']
            : null;

        // Cohérence : quand le receveur (s'il est renseigné) n'a pas de
        // compte Fripay, l'envoyeur DOIT fournir son code de vérification —
        // c'est lui la clé du retrait sur la page web publique.
        // FIX : $recipientUser n'est défini que DANS le bloc ci-dessous (si
        // un numéro de receveur est fourni). Sans initialisation, la ligne
        // "$recipientUser?->fripay_number" plus bas levait
        // "Undefined variable $recipientUser" dès qu'on générait un QR
        // SANS destinataire (cas le plus courant : QR montré à un tiers).
        // NB : l'opérateur nullsafe ?-> protège contre null, pas contre
        // une variable non définie.
        $recipientUser = null;
        if ($recipientPhone !== null) {
            $recipientUser = $this->findUserByAnyNumber($recipientPhone);
            if ($recipientUser === null && $senderValidationCode === null) {
                return response()->json([
                    'type'    => 'VALIDATION_ERROR',
                    'title'   => 'Erreur de validation',
                    'status'  => 422,
                    'detail'  => "Le receveur n'a pas de compte Fripay : vous devez définir un code de vérification à 5 chiffres (champ external_validation_code) que vous lui transmettrez vous-même.",
                    'errors'  => ['external_validation_code' => ["Le code de vérification est requis quand le receveur n'a pas de compte Fripay."]],
                    'request_id' => $request->header('X-Request-Id', ''),
                ], 422);
            }
        }

        $userId = $request->user()->getKey();
        $amount = $validated['amount'];
        $currency = $validated['currency'] ?? 'XOF';
        $expiresAt = isset($validated['expires_minutes'])
            ? now()->addMinutes($validated['expires_minutes'])
            : null;

        // Détection du compte du receveur (§6.e étape 1) — déjà faite plus
        // haut pour valider le code de l'envoyeur.
        $hasRecipientAccount = $recipientPhone ? ($recipientUser !== null) : null;
        // Le code est celui de l'ENVOYEUR ; le générateur automatique ne
        // sert que de filet si un jour l'appli ne le transmet pas.
        $externalCode = ($recipientPhone && !$hasRecipientAccount)
            ? ($senderValidationCode ?? $this->generateExternalCode())
            : null;

        // Numéro Fripay de l'ENVOYEUR : constituant du QR (cahier des
        // charges) — le receveur voit qui lui a envoyé l'argent.
        $senderFripayNumber = $request->user()->fripay_number;

        $keyPair = $this->crypto->generateKeyPair();

        $signed = $this->crypto->createSignedPayload(
            $amount,
            $currency,
            $keyPair['secret_key'],
            $keyPair['public_key'],
            $validated['recipient_hint'] ?? $recipientPhone,
            $expiresAt?->toIso8601String(),
            'mpm',
            null,
            $senderFripayNumber,
            $recipientUser?->fripay_number,
        );

        $idempotencyKey = Str::random(64);

        $qrCode = DB::transaction(function () use (
            $userId, $amount, $currency, $keyPair, $signed, $expiresAt,
            $idempotencyKey, $recipientPhone, $recipientUser, $hasRecipientAccount, $externalCode
        ) {
            // Réservation réelle des fonds — l'argent quitte le wallet de
            // l'envoyeur dès la génération (§6 : le QR "contient" l'argent).
            $this->wallets->debit(
                $userId,
                (float) $amount,
                null,
                'qr_transfer_hold',
                "Réservation QR argent"
            );

            $qr = OfflineQrCode::create([
                'uuid'                      => $signed['uuid'],
                'sender_user_id'            => $userId,
                'amount'                    => $amount,
                'currency'                  => $currency,
                'sender_public_key'         => $this->crypto->publicKeyToBase64($keyPair['public_key']),
                'signature'                 => $signed['signature'],
                'qr_payload'                => $signed['qr_content'],
                'status'                    => OfflineQrCode::STATUS_ACTIVE,
                'expires_at'                => $expiresAt,
                'idempotency_key'           => $idempotencyKey,
                'recipient_phone'           => $recipientPhone,
                'has_recipient_account'     => $hasRecipientAccount,
                'recipient_user_id'         => $hasRecipientAccount ? $recipientUser->id : null,
                'external_validation_code'  => $externalCode,
                'held_at'                   => now(),
            ]);

            OfflineQrEvent::create([
                'offline_qr_code_id' => $qr->id,
                'event_type'         => OfflineQrEvent::EVENT_HELD,
                'actor_user_id'      => $userId,
                'metadata'           => ['amount' => $amount, 'currency' => $currency],
            ]);

            OfflineQrEvent::create([
                'offline_qr_code_id' => $qr->id,
                'event_type'         => OfflineQrEvent::EVENT_GENERATED,
                'actor_user_id'      => $userId,
                'metadata'           => [
                    'amount'                 => $amount,
                    'currency'               => $currency,
                    'recipient_phone'        => $recipientPhone,
                    'has_recipient_account'  => $hasRecipientAccount,
                ],
            ]);

            if ($externalCode !== null) {
                OfflineQrEvent::create([
                    'offline_qr_code_id' => $qr->id,
                    'event_type'         => OfflineQrEvent::EVENT_EXTERNAL_CODE_ISSUED,
                    'actor_user_id'      => $userId,
                    'metadata'           => ['recipient_phone' => $recipientPhone],
                ]);
            }

            return $qr;
        });

        $this->crypto->wipeKey($keyPair['secret_key']);

        Log::info('QR Code généré', [
            'uuid'   => $signed['uuid'],
            'user'   => $userId,
            'amount' => $amount,
        ]);

        return response()->json([
            'qr_code'                => $signed['qr_content'],
            'uuid'                   => $signed['uuid'],
            'amount'                 => $amount,
            'currency'               => $currency,
            'expires_at'             => $expiresAt?->toIso8601String(),
            'status'                 => 'active',
            'has_recipient_account'  => $hasRecipientAccount,
            // Code de vérification défini par l'ENVOYEUR (cahier des
            // charges) — à transmettre par ses soins hors appli si le
            // receveur n'a pas de compte Fripay.
            'external_validation_code' => $externalCode,
            // Numéro Fripay de l'envoyeur (constituant du QR).
            'sender_fripay_number'   => $senderFripayNumber,
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
                'NOT_FOUND'     => $this->errorResponse('QR_NOT_FOUND', 'QR Code introuvable', 404, 'Ce QR Code est inconnu ou a été supprimé.', $request),
                'NOT_ACTIVE'    => $this->errorResponse('QR_NOT_ACTIVE', 'QR Code non actif', 422, "Ce QR Code n'est plus actif (déjà réclamé, expiré ou annulé).", $request),
                'MERCHANT_QR'   => $this->errorResponse('MERCHANT_QR', 'QR marchand', 422, "Ce QR est un QR marchand, pas un QR d'envoi d'argent.", $request),
                'SELF_TRANSFER' => $this->errorResponse('SELF_TRANSFER', 'QR personnel', 422, 'Vous ne pouvez pas recevoir votre propre QR Code.', $request),
                'PUBKEY_MISMATCH' => $this->errorResponse('PUBKEY_MISMATCH', 'QR invalide', 422, 'La clé publique du QR ne correspond pas à celle enregistrée.', $request),
                default => $this->errorResponse('UNKNOWN', 'Erreur', 500, 'Une erreur inattendue est survenue.', $request),
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
                'settled_at'        => now(),
                'recipient_user_id' => $userId,
            ]);

            // §7 — l'encaissement doit réellement créditer le wallet du
            // receveur (bug corrigé : le statut changeait mais l'argent
            // débité à la génération n'était jamais crédité côté receveur).
            $this->wallets->credit(
                $userId,
                (float) $qrCode->amount,
                null,
                'qr_redeemed',
                'Encaissement QR argent — de ' . $qrCode->sender_user_id
            );

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
                'NOT_FOUND'      => $this->errorResponse('QR_NOT_FOUND', 'QR Code introuvable', 404, 'Ce QR Code est inconnu ou a été supprimé.', $request),
                'NOT_REDEEMABLE' => $this->errorResponse('QR_NOT_REDEEMABLE', 'QR non encaissable', 422, 'Ce QR Code ne peut plus être encaissé.', $request),
                'MERCHANT_QR'    => $this->errorResponse('MERCHANT_QR', 'QR marchand', 422, "Ce QR est un QR marchand, pas un QR d'envoi d'argent.", $request),
                'NOT_OWNER'      => $this->errorResponse('NOT_OWNER', 'QR non autorisé', 403, "Ce QR Code ne vous appartient pas.", $request),
                default => $this->errorResponse('UNKNOWN', 'Erreur', 500, 'Une erreur inattendue est survenue.', $request),
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
                'NOT_FOUND'           => $this->errorResponse('QR_NOT_FOUND', 'QR Code introuvable', 404, 'Ce QR Code est inconnu ou a été supprimé.', $request),
                'NOT_TRANSFERABLE'    => $this->errorResponse('QR_NOT_TRANSFERABLE', 'QR non transférable', 422, 'Ce QR Code ne peut plus être transféré.', $request),
                'MERCHANT_QR'         => $this->errorResponse('MERCHANT_QR', 'QR marchand', 422, "Ce QR est un QR marchand, pas un QR d'envoi d'argent.", $request),
                'NOT_OWNER'           => $this->errorResponse('NOT_OWNER', 'QR non autorisé', 403, "Ce QR Code ne vous appartient pas.", $request),
                'RECIPIENT_NOT_FOUND' => $this->errorResponse('RECIPIENT_NOT_FOUND', 'Destinataire introuvable', 404, "Aucun compte FriPay ne correspond à ce numéro.", $request),
                default => $this->errorResponse('UNKNOWN', 'Erreur', 500, 'Une erreur inattendue est survenue.', $request),
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

            $qrCode->update([
                'status'      => OfflineQrCode::STATUS_REVOKED,
                'refunded_at' => now(),
            ]);

            // §7 — même bug que redeem() : la révocation changeait le statut
            // et promettait un "refund" dans les métadonnées de l'event,
            // sans jamais recréditer l'expéditeur. Corrigé.
            $this->wallets->credit(
                $userId,
                (float) $qrCode->amount,
                null,
                'qr_revoked_refund',
                'Remboursement QR argent — annulation par expéditeur'
            );

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
                'NOT_FOUND'      => $this->errorResponse('QR_NOT_FOUND', 'QR Code introuvable', 404, 'Ce QR Code est inconnu ou a été supprimé.', $request),
                'NOT_SENDER'     => $this->errorResponse('NOT_SENDER', 'QR non autorisé', 403, "Seul l'expéditeur peut annuler ce QR Code.", $request),
                'NOT_REVOCABLE'  => $this->errorResponse('QR_NOT_REVOCABLE', 'QR non annulable', 422, 'Ce QR Code a déjà été encaissé ou annulé.', $request),
                default => $this->errorResponse('UNKNOWN', 'Erreur', 500, 'Une erreur inattendue est survenue.', $request),
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
            'expires_at'  => $qrCode->expires_at?->toIso8601String(),
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
