<?php

namespace App\Services\Connectors;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur agrégateur FeexPay (https://feexpay.me).
 *
 * Utilisé pour la RECHARGE du wallet FriPay (cash-in via mobile money) en
 * attendant les API natives MTN MoMo / Moov Money / Celtiis Cash. FeexPay
 * est un agrégateur béninois : la collecte passe par les mêmes réseaux
 * MTN/Moov, mais avec UN SEUL contrat marchand (shop ID + token API).
 *
 * Endpoints publics (SDK officiels feexpay-php / feexpay-node-sdk) :
 *   POST https://api.feexpay.me/api/transactions/requesttopay/integration
 *        { phoneNumber, amount, reseau, token, shop, first_name, email,
 *          callback_info, reference }
 *   GET  https://api.feexpay.me/api/transactions/getrequesttopay/integration/{reference}
 *        -> { status: PENDING|SUCCESSFUL|FAILED, amount, payer{partyId}, reference }
 *
 * NOTE : FeexPay n'expose pas encore d'endpoint public de disbursement
 * (payout). Le retrait vers mobile money reste donc sur les connecteurs
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
            'phoneNumber'  => $params['phone'],
            'amount'       => (int) $params['amount'],
            'reseau'       => strtoupper($params['operator']), // MTN | MOOV
            'token'        => $this->config('token'),
            'shop'         => $this->config('id'),
            'first_name'   => trim(($params['first_name'] ?? '') . ' ' . ($params['last_name'] ?? '')),
            'email'        => $params['email'] ?? '',
            'callback_info' => $params['description'] ?? 'Recharge wallet FriPay',
            'reference'    => $params['reference'],
        ];

        try {
            $response = Http::baseUrl($this->config('base_url'))
                ->timeout(30)
                ->asForm()
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
            $response = Http::baseUrl($this->config('base_url'))
                ->timeout(20)
                ->get('/api/transactions/getrequesttopay/integration/' . rawurlencode($feexpayReference));
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
            'clientNum' => $body['payer']['partyId'] ?? null,
            'message'   => 'Statut récupéré',
        ];
    }

    /**
     * Opérateurs mobile money supportés par la collecte FeexPay.
     * (Celtiis Cash n'est pas couvert par requesttopay — voir README.)
     */
    public static function supportedOperators(): array
    {
        return ['MTN', 'MOOV'];
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
