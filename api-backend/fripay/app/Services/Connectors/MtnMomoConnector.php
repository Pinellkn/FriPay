<?php

namespace App\Services\Connectors;

use App\Contracts\TransferConnector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;

/**
 * Connecteur MTN MoMo — produit Disbursements (payout).
 *
 * Docs : https://momodeveloper.mtn.com (API MoMo, produit Disbursements)
 *
 * Flux : token OAuth2 (POST /disbursement/token/) puis envoi du transfert
 * (POST /disbursement/v1_0/transfer). Le statut final arrive par callback
 * HTTP sur X-Callback-Url (SUCCESSFUL / FAILED / PENDING).
 *
 * Idempotence : le X-Reference-Id MTN est un UUID déterministe dérivé de la
 * référence FriPay. Un rejeu de l'outbox réutilise donc le même identifiant :
 * MTN renverra HTTP 409 (déjà soumis) et on interroge alors le statut du
 * transfert existant au lieu de le soumettre à nouveau.
 */
class MtnMomoConnector implements TransferConnector
{
    private const TOKEN_CACHE_KEY = 'mtn_momo_access_token';

    public function isConfigured(): bool
    {
        return (bool) (
            $this->config('api_user')
            && $this->config('api_key')
            && $this->config('subscription_key')
        );
    }

    public function initiateTransfer(array $payload): array
    {
        if (! $this->isConfigured()) {
            return [
                'success'      => false,
                'retryable'    => false,
                'transaction_id' => null,
                'message'      => 'MTN MoMo non configuré (clés API manquantes)',
            ];
        }

        $referenceId = $this->referenceIdFor($payload['reference']);

        try {
            $response = Http::baseUrl($this->config('base_url'))
                ->withToken($this->accessToken())
                ->withHeaders([
                    'X-Reference-Id'              => $referenceId,
                    'X-Target-Environment'        => $this->config('target_environment'),
                    'Ocp-Apim-Subscription-Key'   => $this->config('subscription_key'),
                    'X-Callback-Url'              => $this->config('callback_url'),
                ])
                ->timeout(30)
                ->post('/disbursement/v1_0/transfer', [
                    'amount'      => number_format((float) $payload['amount'], 2, '.', ''),
                    'currency'    => 'XOF',
                    'externalId'  => $payload['reference'],
                    'payee'       => [
                        'partyIdType' => 'MSISDN',
                        'partyId'     => ltrim($payload['recipient_phone'], '+'),
                    ],
                    'payerMessage' => $payload['description'] ?? 'Transfert FriPay',
                    'payeeNote'    => 'Transfert FriPay',
                ]);
        } catch (\Throwable $e) {
            return [
                'success'        => false,
                'retryable'      => true,
                'transaction_id' => null,
                'message'        => 'MTN MoMo injoignable : ' . $e->getMessage(),
            ];
        }

        $status = $response->status();

        // 200/201/202 : transfert accepté pour traitement.
        if (in_array($status, [200, 201, 202], true)) {
            return [
                'success'        => true,
                'retryable'      => false,
                'transaction_id' => $referenceId,
                'message'        => 'Transfert accepté par MTN MoMo',
            ];
        }

        // 409 : le X-Reference-Id a déjà été utilisé (rejeu idempotent).
        // On interroge le statut du transfert existant au lieu de re-soumettre.
        if ($status === 409) {
            return $this->checkStatus($referenceId);
        }

        // 4xx (hors 409) : rejet définitif (données invalides, identifiants erronés).
        if ($status >= 400 && $status < 500) {
            return [
                'success'        => false,
                'retryable'      => false,
                'transaction_id' => null,
                'message'        => 'MTN MoMo a rejeté le transfert (HTTP ' . $status . ') : ' . $this->errorBody($response),
            ];
        }

        // 429 / 5xx : indisponibilité temporaire -> rejouable (mode hors-ligne).
        return [
            'success'        => false,
            'retryable'      => true,
            'transaction_id' => null,
            'message'        => 'MTN MoMo indisponible (HTTP ' . $status . ')',
        ];
    }

    /**
     * Interroge le statut d'un transfert soumis à MTN MoMo.
     *
     * Utilisé pour le rejeu idempotent (HTTP 409) ; peut aussi servir au
     * suivi actif si le callback n'arrive pas.
     */
    public function checkStatus(string $referenceId): array
    {
        try {
            $response = Http::baseUrl($this->config('base_url'))
                ->withToken($this->accessToken())
                ->withHeaders([
                    'X-Target-Environment'      => $this->config('target_environment'),
                    'Ocp-Apim-Subscription-Key' => $this->config('subscription_key'),
                ])
                ->timeout(30)
                ->get('/disbursement/v1_0/transfer/' . $referenceId);
        } catch (\Throwable $e) {
            return [
                'success'        => false,
                'retryable'      => true,
                'transaction_id' => $referenceId,
                'message'        => 'MTN MoMo injoignable : ' . $e->getMessage(),
            ];
        }

        if ($response->successful()) {
            $mtnStatus = strtoupper((string) $response->json('status'));

            return [
                'success'        => $mtnStatus === 'SUCCESSFUL',
                'retryable'      => $mtnStatus === 'PENDING',
                'transaction_id' => $response->json('financialTransactionId') ?? $referenceId,
                'message'        => $mtnStatus,
            ];
        }

        return [
            'success'        => false,
            'retryable'      => $response->status() >= 500 || $response->status() === 429,
            'transaction_id' => $referenceId,
            'message'        => 'Statut MTN MoMo indisponible (HTTP ' . $response->status() . ')',
        ];
    }

    /**
     * Token OAuth2 MTN MoMo, mis en cache jusqu'à expiration (env. 1 h).
     */
    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, 3300, function () {
            $response = Http::baseUrl($this->config('base_url'))
                ->withBasicAuth($this->config('api_user'), $this->config('api_key'))
                ->withHeaders([
                    'Ocp-Apim-Subscription-Key' => $this->config('subscription_key'),
                ])
                ->asForm()
                ->post('/disbursement/token/', []);

            if (! $response->ok()) {
                throw new \RuntimeException(
                    'Échec d\'authentification MTN MoMo (HTTP ' . $response->status() . ')'
                );
            }

            return (string) $response->json('access_token');
        });
    }

    /**
     * UUID v5 déterministe dérivé de la référence FriPay : un rejeu de
     * l'outbox réutilise le même X-Reference-Id (pas de double débit).
     */
    private function referenceIdFor(string $reference): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'fripay-mtn:' . $reference)->toString();
    }

    private function config(string $key): ?string
    {
        $value = config('fripay.mtn_momo.' . $key);

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    private function errorBody($response): string
    {
        $body = $response->json();

        return is_array($body) ? json_encode($body) : (string) $response->body();
    }
}
