<?php

namespace App\Services\Connectors;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur agrégateur FeexPay (https://feexpay.me).
 *
 * Utilisé pour la RECHARGE du wallet FriPay (cash-in via mobile) en
 * attendant les API natives MTN MoMo / Moov Mobile / Celtiis Mobile. FeexPay
 * est un agrégateur béninois : la collecte passe par les mêmes réseaux
 * MTN/Moov, mais avec UN SEUL contrat marchand (shop ID + token API).
 *
 * Endpoints publics (SDK officiels feexpay-php / feexpay-node-sdk) :
 *   POST https://api-v2.feexpay.me/api/transactions/requesttopay/integration
 *        { phoneNumber, amount, reseau, token, shop, first_name, email,
 *          callback_info, reference }
 *   GET  https://api-v2.feexpay.me/api/transactions/getrequesttopay/integration/{reference}
 *        -> { status: PENDING|SUCCESSFUL|FAILED, amount, payer{partyId}, reference }
 *
 * NOTE : FeexPay n'expose pas encore d'endpoint public de disbursement
 * (payout). Le retrait vers mobile reste donc sur les connecteurs
 * opérateurs natifs (MTN/Moov/Celtiis) ou le retrait agent.
 *
 * Idempotence : la référence FriPay (topup UUID + référence métier) est
 * transmise telle quelle dans `reference` — un rejeu renvoie la même
 * transaction FeexPay au lieu d'en créer une nouvelle.
 */
class FeexpayConnector
{
    /** Statuts FeexPay normalisés. */
    public const STATUS_PENDING    = 'PENDING';
    public const STATUS_SUCCESSFUL = 'SUCCESSFUL';
    public const STATUS_FAILED     = 'FAILED';

    /**
     * Le connecteur est-il configuré (identifiants marchands présents) ?
     */
    public function isConfigured(): bool
    {
        return (bool) ($this->config('id') && $this->config('token'));
    }

    /**
     * Crée une demande de paiement (collecte) chez FeexPay.
     *
     * @param array $params [
     *   'amount'       => int (XOF),
     *   'phone'        => string (MSISDN, ex. 2290197000000),
     *   'operator'     => string (MTN|MOOV),
     *   'first_name'   => string|null,
     *   'last_name'    => string|null,
     *   'email'        => string|null,
     *   'reference'    => string (référence métier FriPay, clé d'idempotence),
     *   'description'  => string|null,
     * ]
     *
     * @return array [
     *   'success'        => bool,
     *   'retryable'      => bool,
     *   'reference'      => string|null (référence FeexPay),
     *   'payment_url'    => string|null,
     *   'status'         => string|null,
     *   'message'        => string,
     * ]
     */
    public function requestToPay(array $params): array
    {
        if (! $this->isConfigured()) {
            return [
                'success'     => false,
                'retryable'   => false,
                'reference'   => null,
                'payment_url' => null,
                'status'      => null,
                'message'     => 'FeexPay non configuré (FEEXPAY_ID / FEEXPAY_TOKEN manquants)',
            ];
        }

        $payload = [
            // Payload aligné sur le SDK officiel feexpay_flutter_v2
            // (payForItMobile) — FeexPay est strict sur la forme.
            'amount'       => (int) $params['amount'],
            'token'        => $this->config('token'),
            'country'      => 'BJ',
            'currency'     => 'XOF',
            'email'        => $params['email'] ?? '',
            'payment_interface' => 'FLUTTER',
            'otp'          => '',
            'mode'         => '1',
            'first_name'   => $params['first_name'] ?? 'Client',
            'last_name'    => $params['last_name'] ?? 'FriPay',
            'phoneNumber'  => $params['phone'],
            'phoneNumberRight' => preg_replace('/^229/', '', (string) $params['phone']),
            'reseau'       => strtoupper($params['operator']), // MTN | MOOV
            'shop'         => $this->config('id'),
            'callback_info' => $params['description'] ?? 'Recharge wallet FriPay',
            'reference'    => $params['reference'],
        ];

        try {
            // AUTHENTIFICATION PAR HEADER OBLIGATOIRE : FeexPay rejette
            // désormais toute requête sans `Authorization: Bearer <token>`
            // (HTTP 401 UNAUTHORIZED) — le token dans le body ne suffit
            // plus. C'était la cause des recharges en échec 401. Le SDK
            // officiel envoie d'ailleurs les deux (header + body).
            $response = Http::baseUrl($this->config('base_url'))
                ->timeout(30)
                ->asJson()
                ->withToken((string) $this->config('token'))
                ->post('/api/transactions/requesttopay/integration', $payload);
        } catch (\Throwable $e) {
            Log::error('FeexPay requestToPay injoignable', ['error' => $e->getMessage()]);

            return [
                'success'     => false,
                'retryable'   => true,
                'reference'   => null,
                'payment_url' => null,
                'status'      => null,
                'message'     => 'FeexPay injoignable : ' . $e->getMessage(),
            ];
        }

        $body = $response->json() ?? [];
        $status = strtoupper((string) ($body['status'] ?? ''));

        // La référence FeexPay peut venir dans `reference` (cas normal).
        $feexReference = $body['reference'] ?? null;

        if ($response->successful() && $status !== self::STATUS_FAILED && $feexReference) {
            return [
                'success'     => true,
                'retryable'   => false,
                'reference'   => (string) $feexReference,
                'payment_url' => $body['payment_url'] ?? null,
                'status'      => $status ?: self::STATUS_PENDING,
                'message'     => 'Demande de paiement créée chez FeexPay',
            ];
        }

        // 429 / 5xx : indisponibilité temporaire -> rejouable.
        if ($response->status() === 429 || $response->status() >= 500) {
            return [
                'success'     => false,
                'retryable'   => true,
                'reference'   => $feexReference,
                'payment_url' => null,
                'status'      => $status ?: null,
                'message'     => 'FeexPay indisponible (HTTP ' . $response->status() . ')',
            ];
        }

        // 401 : identifiants marchands refusés (token/shop) — message lisible
        // côté appli au lieu du JSON brut d'origine (qui fuyait en UI).
        if ($response->status() === 401) {
            Log::error('FeexPay 401 UNAUTHORIZED — vérifier FEEXPAY_ID / FEEXPAY_TOKEN', [
                'body' => $this->errorBody($body, $response),
            ]);

            return [
                'success'     => false,
                'retryable'   => false,
                'reference'   => $feexReference,
                'payment_url' => null,
                'status'      => null,
                'message'     => 'Paiement momentanément indisponible (identifiants FeexPay refusés). Contactez le support si cela persiste.',
            ];
        }

        return [
            'success'     => false,
            'retryable'   => false,
            'reference'   => $feexReference,
            'payment_url' => null,
            'status'      => $status ?: null,
            'message'     => 'FeexPay a rejeté la demande (HTTP ' . $response->status() . ') : '
                . $this->errorBody($body, $response),
        ];
    }

    /**
     * Interroge le statut d'une transaction FeexPay.
     *
     * @return array [
     *   'status'    => 'PENDING'|'SUCCESSFUL'|'FAILED'|null,
     *   'amount'    => float|null,
     *   'clientNum' => string|null,
     *   'message'   => string,
     * ]
     */
    public function getStatus(string $feexpayReference): array
    {
        if (! $this->isConfigured()) {
            return ['status' => null, 'amount' => null, 'clientNum' => null, 'message' => 'FeexPay non configuré'];
        }

        try {
            // Endpoint de statut aligné sur le SDK officiel (payforit_status) :
            // GET /api/transactions/public/single/status/{reference}. L'ancien
            // endpoint getrequesttopay répondait 404 (route inexistante côté
            // FeexPay) — aucun statut n'était jamais récupéré. Header Bearer
            // requis, comme pour requesttopay.
            $response = Http::baseUrl($this->config('base_url'))
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
            return [
                'status'    => null,
                'amount'    => null,
                'clientNum' => null,
                'message'   => 'Statut FeexPay indisponible (HTTP ' . $response->status() . ')',
            ];
        }

        $body = $response->json() ?? [];

        return [
            'status'    => strtoupper((string) ($body['status'] ?? '')) ?: null,
            'amount'    => isset($body['amount']) ? (float) $body['amount'] : null,
            // Le numéro du payeur vient en `phoneNumber` (pas `payer.partyId`,
            // format de l'ancienne API MTN directe).
            'clientNum' => $body['phoneNumber'] ?? null,
            'message'   => 'Statut récupéré',
        ];
    }

    /**
     * Opérateurs mobiles supportés par la collecte FeexPay.
     * MTN MoMo, Moov Mobile et Celtiis Mobile (codes `reseau` FeexPay).
     */
    public static function supportedOperators(): array
    {
        return ['MTN', 'MOOV', 'CELTIIS'];
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
