<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionStatusHistory;
use App\Models\WebhookEvent;
use App\Services\PaymentLinkService;
use App\Services\TopupService;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private readonly TransferService $transferService,
        private readonly TopupService $topupService,
        private readonly PaymentLinkService $paymentLinkService,
    ) {}

    /**
     * POST /api/v1/webhooks/feexpay
     *
     * Callback FeexPay (collecte request-to-pay). Le SDK officiel transmet
     * callback_info / reference dans le corps — la référence FeexPay permet
     * de retrouver le topup. Le callback arrive SANS signature : on journalise
     * l'IP et on vérifie le statut directement auprès de l'API FeexPay
     * (source de vérité) avant de créditer — un appel forgé ne peut donc pas
     * créditer un wallet.
     *
     * Payload attendu (variables selon l'événement) :
     * { reference, status, amount, phoneNumber, ... }
     */
    public function handleFeexpay(Request $request): JsonResponse
    {
        $payload = $request->all();

        $providerReference = (string) ($payload['reference'] ?? '');

        $webhookEvent = WebhookEvent::create([
            'provider'        => 'feexpay',
            'signature_valid' => false, // pas de signature HMAC chez FeexPay
            'payload'         => $payload,
            'processed'       => false,
        ]);

        if ($providerReference === '') {
            Log::warning('Webhook FeexPay sans référence', ['ip' => $request->ip()]);

            return response()->json(['status' => 'ok'], 200); // 200 pour éviter les retries inutiles
        }

        // Source de vérité : on re-interroge l'API FeexPay plutôt que de
        // faire confiance au corps du callback (pas de signature disponible).
        // Deux objets métier peuvent être concernés : recharge (topup) ou
        // paiement d'un FriPay Link.
        $topup = $this->topupService->refreshStatusByProviderReference($providerReference);

        if (! $topup) {
            // Pas un topup : peut-être le paiement d'un FriPay Link. La
            // vérification API FeexPay reste la source de vérité — un
            // callback forgé ne peut pas créditer un wallet.
            $link = $this->paymentLinkService->confirmByProviderReference($providerReference);

            if ($link) {
                $webhookEvent->update(['processed' => true]);

                Log::info('Webhook FeexPay traité (FriPay Link)', [
                    'reference' => $providerReference,
                    'link'      => $link->id,
                    'status'    => $link->status,
                ]);

                return response()->json(['status' => 'ok'], 200);
            }
        }

        $webhookEvent->update(['processed' => true]);

        Log::info('Webhook FeexPay traité', [
            'reference' => $providerReference,
            'topup'     => $topup?->id,
            'status'    => $topup?->status,
        ]);

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * POST /api/v1/webhooks/aggregator/{provider}
     */
    public function handleAggregator(Request $request, string $provider): JsonResponse
    {
        $signature = $request->header('X-Signature');
        $payload = $request->getContent();

        $webhookEvent = WebhookEvent::create([
            'provider' => $provider,
            'signature_valid' => $this->verifySignature($signature, $payload, $provider),
            'payload' => $request->all(),
            'processed' => false,
        ]);

        if (!$webhookEvent->signature_valid) {
            Log::warning("Invalid webhook signature from provider: {$provider}", [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'type' => 'INVALID_SIGNATURE',
                'title' => 'Signature invalide',
                'status' => 401,
                'detail' => 'La signature du webhook est invalide.',
                'request_id' => $request->header('X-Request-Id', ''),
            ], 401);
        }

        $this->processWebhook($webhookEvent);

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * POST /api/v1/webhooks/pispi
     */
    public function handlePispi(Request $request): JsonResponse
    {
        return $this->handleAggregator($request, 'pispi');
    }

    /**
     * POST /api/v1/webhooks/mtn
     *
     * Callback natif MTN MoMo (produit Disbursements). MTN n'envoie pas de
     * signature HMAC : on vérifie l'IP source contre la whitelist configurée.
     * Payload : { externalId, financialTransactionId, status, amount, ... }
     */
    public function handleMtn(Request $request): JsonResponse
    {
        // Vérification IP source (MTN n'utilise pas de signature HMAC)
        $clientIp = $request->ip();
        $allowedIps = config('services.mtn.allowed_ips', []);

        $signatureValid = empty($allowedIps) || in_array($clientIp, $allowedIps, true);

        $payload = $request->all();

        $webhookEvent = WebhookEvent::create([
            'provider'        => 'mtn',
            'signature_valid' => $signatureValid,
            'payload'         => $payload,
            'processed'       => false,
        ]);

        if (!$signatureValid) {
            Log::warning('MTN webhook from unauthorized IP', [
                'ip' => $clientIp,
                'allowed' => $allowedIps,
            ]);

            return response()->json([
                'type' => 'UNAUTHORIZED_SOURCE',
                'title' => 'Source non autorisée',
                'status' => 403,
                'detail' => 'IP source non autorisée pour les webhooks MTN.',
                'request_id' => $request->header('X-Request-Id', ''),
            ], 403);
        }

        $this->processMtnWebhook($webhookEvent);

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Applique un callback MTN MoMo : statut SUCCESSFUL/FAILED/PENDING.
     */
    private function processMtnWebhook(WebhookEvent $event): void
    {
        $payload = $event->payload;
        $externalId = $payload['externalId'] ?? null;

        if (! $externalId) {
            $event->update(['processing_error' => 'Missing externalId']);
            return;
        }

        $transaction = Transaction::where('reference', $externalId)->first();

        if (! $transaction) {
            $event->update(['processing_error' => 'Transaction not found: ' . $externalId]);
            return;
        }

        // Anti-rejeu : verifier si un webhook MTN pour cette transaction a deja ete traite
        $alreadyProcessed = WebhookEvent::where('provider', 'mtn')
            ->where('processed', true)
            ->where('id', '!=', $event->id)
            ->whereJsonContains('payload', ['externalId' => $externalId])
            ->exists();

        if ($alreadyProcessed) {
            $event->update(['processing_error' => 'Duplicate webhook ignored (already processed)']);
            return;
        }

        $newStatus = match (strtoupper((string) ($payload['status'] ?? ''))) {
            'SUCCESSFUL' => 'succeeded',
            'FAILED'     => 'failed',
            'PENDING'    => 'pending',
            default      => null,
        };

        if ($newStatus) {
            // Garde d'idempotence : une transaction déjà finalisée
            // (succeeded/failed/cancelled/completed) ne change plus d'état.
            // Sans cette garde, un webhook retardé sur une transaction déjà
            // annulée la passait en 'failed' ET déclenchait refundWallet(),
            // créant un crédit sans débit correspondant.
            if (in_array($transaction->status, ['succeeded', 'failed', 'cancelled', 'completed'], true)) {
                $event->update(['processing_error' => "Transaction already finalized (status: {$transaction->status}) — webhook ignored"]);
                return;
            }

            $previousStatus = $transaction->status;
            $transaction->update([
                'status'             => $newStatus,
                'external_reference' => $payload['financialTransactionId'] ?? $transaction->external_reference,
                'completed_at'       => in_array($newStatus, ['succeeded', 'failed']) ? now() : $transaction->completed_at,
            ]);

            TransactionStatusHistory::create([
                'transaction_id'  => $transaction->id,
                'previous_status' => $previousStatus,
                'new_status'      => $newStatus,
                'source'          => 'webhook',
                'note'            => 'Callback MTN MoMo',
            ]);

            if ($newStatus === 'failed') {
                $this->transferService->refundWallet($transaction, 'transfer_refund_webhook_failed');
            }
        }

        $event->update(['processed' => true]);
    }

    /**
     * Verify signature using HMAC.
     */
    private function verifySignature(?string $signature, string $payload, string $provider): bool
    {
        if (!$signature) {
            return false;
        }

        $secret = config("services.{$provider}.webhook_secret", '');

        if (empty($secret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Process a webhook event (update transaction status).
     */
    private function processWebhook(WebhookEvent $event): void
    {
        $payload = $event->payload;
        $externalRef = $payload['transaction_id'] ?? $payload['reference'] ?? null;

        if (!$externalRef) {
            $event->update(['processing_error' => 'Missing transaction reference']);
            return;
        }

        $transaction = Transaction::where('external_reference', $externalRef)->first();

        if (!$transaction) {
            $event->update(['processing_error' => 'Transaction not found']);
            return;
        }

        // Anti-rejeu : verifier si un webhook pour cette transaction a deja ete traite
        $alreadyProcessed = WebhookEvent::where('processed', true)
            ->where('id', '!=', $event->id)
            ->where(function ($q) use ($externalRef) {
                $q->whereJsonContains('payload', ['transaction_id' => $externalRef])
                  ->orWhereJsonContains('payload', ['reference' => $externalRef]);
            })
            ->exists();

        if ($alreadyProcessed) {
            $event->update(['processing_error' => 'Duplicate webhook ignored (already processed)']);
            return;
        }

        $newStatus = match ($payload['status'] ?? '') {
            'success', 'completed' => 'succeeded',
            'failed', 'error' => 'failed',
            'pending' => 'pending',
            default => null,
        };

        if ($newStatus) {
            // Garde d'idempotence (cf. processMtnWebhook) : ne jamais
            // re-finaliser une transaction déjà terminée, ni rembourser
            // une seconde fois.
            if (in_array($transaction->status, ['succeeded', 'failed', 'cancelled', 'completed'], true)) {
                $event->update(['processing_error' => "Transaction already finalized (status: {$transaction->status}) — webhook ignored"]);
                return;
            }

            $previousStatus = $transaction->status;
            $transaction->update([
                'status' => $newStatus,
                'completed_at' => in_array($newStatus, ['succeeded', 'failed']) ? now() : $transaction->completed_at,
            ]);

            TransactionStatusHistory::create([
                'transaction_id' => $transaction->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'source' => 'webhook',
                'note' => "Mis à jour via webhook {$event->provider}",
            ]);

            if ($newStatus === 'failed') {
                $this->transferService->refundWallet($transaction, 'transfer_refund_webhook_failed');
            }
        }

        $event->update(['processed' => true]);
    }
}
