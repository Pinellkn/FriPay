<?php

namespace App\Services\Connectors;

use App\Contracts\TransferConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur agrégateur FeexPay — PAYOUTS (envois FriPay -> MTN/Moov/Celtiis).
 *
 * Doc : https://docs.feexpay.me — section API > Paiement (Retrait).
 *
 *   POST https://api-v2.feexpay.me/api/payouts/public/transfer/global
 *        { shop, amount (>= 50), phoneNumber (22901XXXXXXXX), network
 *          (MTN|MOOV|CELTIIS), motif (<= 30 car., sans caractères spéciaux),
 *          callback_info (optionnel) }
 *        En-tête OBLIGATOIRE : Authorization: Bearer <FEEXPAY_TOKEN>
 *
 *        -> 200 { reference, status: "PENDING", message, phone_number, amount,
 *                 callback_info }
 *
 * Le statut final (SUCCESSFUL / FAILED) est confirmé soit par le webhook
 * FeexPay (POST /api/v1/webhooks/feexpay, nécessite une URL publique — pas
 * le cas en dev local), soit par vérification active
 * (job RefreshPendingPayouts -> checkPayoutStatus()).
 *
 * ⚠️ WHITELIST IP : FeexPay rejette tout payout (HTTP 403 IP_NOT_ALLOWED)
 * depuis une IP non déclarée dans le tableau de bord marchand (onglet
 * « IP List »). Tant que l'IP du serveur n'est pas whitelistée, les transferts
 * restent en file d'attente (outbox) et partiront automatiquement après
 * déclaration de l'IP — comportement voulu (retryable = true).
 *
 * ⚠️ Idempotence LIMITÉE : contrairement à la collecte (requesttopay), la
 * doc payout n'expose pas de clé d'idempotence côté FeexPay. La référence
 * FriPay est transmise dans `callback_info` (renvoyée par le webhook) pour
 * la réconciliation ; en cas de réponse perdue après création chez FeexPay,
 * un rejeu de l'outbox pourrait créer un second payout — à surveiller tant
 * que FeexPay n'expose pas d'endpoint idempotent.
 */
class FeexpayPayoutConnector implements TransferConnector
{
    /** Montant minimum par payout (doc FeexPay). */
    public const MIN_AMOUNT = 50;

    public function isConfigured(): bool
    {
        return (bool) ($this->config('id') && $this->config('token'));
    }

    public function initiateTransfer(array $payload): array
    {
        if (! $this->isConfigured()) {
            return [
                'success'        => false,
                'retryable'      => false,
                'transaction_id' => null,
                'message'        => 'FeexPay non configuré (FEEXPAY_ID / FEEXPAY_TOKEN manquants)',
            ];
        }

        $network = strtoupper((string) ($payload['operator_code'] ?? ''));

        if (! in_array($network, ['MTN', 'MOOV', 'CELTIIS'], true)) {
            return [
                'success'        => false,
                'retryable'      => false,
                'transaction_id' => null,
                'message'        => "Réseau non supporté par le payout FeexPay : {$network}",
            ];
        }

        $amount = (int) $payload['amount'];

        if ($amount < self::MIN_AMOUNT) {
            return [
                'success'        => false,
                'retryable'      => false,
                'transaction_id' => null,
                'message'        => 'Montant inférieur au minimum FeexPay (' . self::MIN_AMOUNT . ' FCFA)',
            ];
        }

        // Doc : numéro à 10 chiffres avec préfixe 01, exemple "2290166000000"
        // (E.164 sans le "+"). Nos téléphones sont stockés "+22901XXXXXXXX".
        $phone = ltrim((string) $payload['recipient_phone'], '+');

        // Motif : 30 caractères max, sans caractères spéciaux (doc).
        $motif = mb_substr(
            preg_replace('/[^A-Za-z0-9 ]/', '', (string) ($payload['description'] ?? 'Fripay')) ?: 'Fripay',
            0,
            30
        ) ?: 'Fripay';

        try {
            $response = Http::baseUrl((string) $this->config('base_url'))
                ->timeout(30)
                ->asJson()
                ->withToken((string) $this->config('token'))
                ->post('/api/payouts/public/transfer/global', [
                    'shop'          => $this->config('id'),
                    'amount'        => $amount,
                    'phoneNumber'   => $phone,
                    'network'       => $network,
                    'motif'         => $motif,
                    // Renvoyé tel quel par le webhook : sert à retrouver la
                    // transaction FriPay lors de la confirmation finale.
                    'callback_info' => (string) ($payload['reference'] ?? ''),
                ]);
        } catch (\Throwable $e) {
            Log::error('FeexPay payout injoignable', ['error' => $e->getMessage()]);

            return [
                'success'        => false,
                'retryable'      => true,
                'transaction_id' => null,
                'message'        => 'FeexPay injoignable : ' . $e->getMessage(),
            ];
        }

        $body = $response->json() ?? [];
        $reference = $body['reference'] ?? null;

        // 2xx + référence : payout accepté (statut initial PENDING).
        if ($response->successful() && $reference) {
            return [
                'success'        => true,
                'retryable'      => false,
                'transaction_id' => (string) $reference,
                'message'        => (string) ($body['message'] ?? 'Payout accepté par FeexPay'),
            ];
        }

        $code = (string) ($body['code'] ?? '');

        // 403 IP_NOT_ALLOWED : l'IP du serveur n'est pas whitelistée chez
        // FeexPay (tableau de bord > IP List). Temporaire : dès que l'IP est
        // déclarée, l'outbox rejouera les transferts en attente tout seul.
        if ($response->status() === 403 || $code === 'IP_NOT_ALLOWED') {
            Log::warning('FeexPay payout refusé — IP non whitelistée', ['ip' => $body['message'] ?? null]);

            return [
                'success'        => false,
                'retryable'      => true,
                'transaction_id' => null,
                'message'        => 'Payout bloqué : IP serveur non autorisée chez FeexPay (à déclarer dans le tableau de bord, onglet IP List)',
            ];
        }

        // 401 : identifiants marchands refusés — config à corriger, un rejeu
        // ne peut pas suffire.
        if ($response->status() === 401) {
            Log::error('FeexPay payout 401 — vérifier FEEXPAY_ID / FEEXPAY_TOKEN');

            return [
                'success'        => false,
                'retryable'      => false,
                'transaction_id' => null,
                'message'        => 'Identifiants FeexPay refusés (payout)',
            ];
        }

        // 429 / 5xx : indisponibilité temporaire -> rejouable.
        if ($response->status() === 429 || $response->status() >= 500) {
            return [
                'success'        => false,
                'retryable'      => true,
                'transaction_id' => null,
                'message'        => 'FeexPay indisponible (HTTP ' . $response->status() . ')',
            ];
        }

        // 400/422 : rejet métier définitif (montant, numéro, réseau...).
        return [
            'success'        => false,
            'retryable'      => false,
            'transaction_id' => null,
            'message'        => 'FeexPay a rejeté le payout (HTTP ' . $response->status() . ') : '
                . $this->errorBody($body, $response),
        ];
    }

    /**
     * Vérifie le statut final d'un payout chez FeexPay.
     *
     * Endpoint « Statut des paiements » : la doc V2 propose des routes
     * dédiées /api/payouts/... (non découvertes — 404 à l'écriture) ; la
     * route de statut des transactions
     * GET /api/transactions/public/single/status/{reference} répond
     * elle de façon applicative (404 « Resource not found » pour une
     * référence inconnue) et est donc utilisée comme source de statut.
     *
     * @return array {
     *   'status'    => 'PENDING'|'SUCCESSFUL'|'FAILED'|null,
     *   'amount'    => float|null,
     *   'clientNum' => string|null,
     *   'message'   => string,
     * }
     */
    public function checkPayoutStatus(string $feexpayReference): array
    {
        if (! $this->isConfigured()) {
            return ['status' => null, 'amount' => null, 'clientNum' => null, 'message' => 'FeexPay non configuré'];
        }

        try {
            $response = Http::baseUrl((string) $this->config('base_url'))
                ->timeout(20)
                ->withToken((string) $this->config('token'))
                ->get('/api/transactions/public/single/status/' . rawurlencode($feexpayReference));
        } catch (\Throwable $e) {
            return [
                'status'    => null,
                'amount'    => null,
                'clientNum' => null,
                'message'   => 'FeexPay injoignable : ' . $e->getMessage(),
            ];
        }

        if (! $response->successful()) {
            // 404 applicatif = référence inconnue côté FeexPay : statut
            // indéterminé, on ne conclut PAS à un échec.
            return [
                'status'    => null,
                'amount'    => null,
                'clientNum' => null,
                'message'   => 'Statut payout indisponible (HTTP ' . $response->status() . ')',
            ];
        }

        $body = $response->json() ?? [];

        return [
            'status'    => strtoupper((string) ($body['status'] ?? '')) ?: null,
            'amount'    => isset($body['amount']) ? (float) $body['amount'] : null,
            'clientNum' => $body['phoneNumber'] ?? null,
            'message'   => 'Statut récupéré',
        ];
    }

    private function config(string $key): ?string
    {
        $value = config('fripay.feexpay.' . $key);

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    private function errorBody(array $json, $response): string
    {
        if (is_array($json) && $json !== []) {
            return json_encode($json, JSON_UNESCAPED_UNICODE);
        }

        return (string) substr($response->body(), 0, 300);
    }
}
