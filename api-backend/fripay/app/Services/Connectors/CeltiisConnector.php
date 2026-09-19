<?php

namespace App\Services\Connectors;

use App\Contracts\TransferConnector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;

/**
 * Connecteur Celtiis Cash — via agrégateur PayDunya (API Disbursement).
 *
 * Celtiis Cash n'a pas d'API publique. Ce connecteur utilise l'API
 * Disbursement de PayDunya (https://developers.paydunya.com).
 *
 * Docs PayDunya : https://developers.paydunya.com/doc/EN/api_deboursement
 *
 * Flux : token OAuth2 (POST /disbursement/token/) puis envoi du transfert
 * (POST /disbursement/v1_0/transfer). Le statut final arrive par callback
 * HTTP sur X-Callback-Url (SUCCESSFUL / FAILED / PENDING).
 *
 * Idempotence : le X-Reference-Id est un UUID déterministe dérivé de la
 * référence FriPay. Un rejeu de l'outbox réutilise donc le même identifiant :
 * PayDunya renverra HTTP 409 (déjà soumis) et on interroge alors le statut
 * du transfert existant au lieu de le soumettre à nouveau.
 */
class CeltiisConnector implements TransferConnector
{
    private const TOKEN_CACHE_KEY = 'celtiis_paydunya_token';

    public function isConfigured(): bool
    {
        return (bool) (
            $this->config('master_key')
            && $this->config('private_key')
            && $this->config('token')
        );
    }

    public function initiateTransfer(array $payload): array
    {
        if (! $this->isConfigured()) {
            return [
                'success'        => false,
                'retryable'      => false,
                'transaction_id' => null,
                'message'        => 'Celtiis Cash non configuré (clés API PayDunya manquantes)',
            ];
        }

        $referenceId = $this->referenceIdFor($payload['reference']);

        try {
            $response = Http::baseUrl($this->config('base_url'))
                ->withToken($this->accessToken())
                ->withHeaders([
                    'X-Reference-Id'            => $referenceId,
                    'X-Target-Environment'      => $this->config('target_environment'),
                    'Ocp-Apim-Subscription-Key' => $this->config('token'),
                    'X-Callback-Url'            => $this->config('callback_url'),
                ])
                ->timeout(30)
                ->post('/disbursement/v1_0/transfer', [
                    'amount'       => number_format((float) $payload['amount'], 2, '.', ''),
                    'currency'     => 'XOF',
                    'externalId'   => $payload['reference'],
                    'payee'        => [
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
                'message'        => 'Celtiis Cash injoignable : ' . $e->getMessage(),
            ];
        }

        $status = $response->status();

        // 200/201/202 : transfert accepté pour traitement.
        if (in_array($status, [200, 201, 202], true)) {
            return [
                'success'        => true,
                'retryable'      => false,
                'transaction_id' => $referenceId,
                'message'        => 'Transfert accepté par Celtiis Cash (via PayDunya)',
            ];
        }

        // 409 : le X-Reference-Id a déjà été utilisé (rejeu idempotent).
        if ($status === 409) {
            return $this->checkStatus($referenceId);
        }

        // 4xx (hors 409) : rejet définitif.
        if ($status >= 400 && $status < 500) {
            return [
                'success'        => false,
                'retryable'      => false,
                'transaction_id' => null,
                'message'        => 'Celtiis Cash a rejeté le transfert (HTTP ' . $status . ') : ' . $this->errorBody($response),
            ];
        }

        // 429 / 5xx : indisponibilité temporaire -> rejouable.
        return [
            'success'        => false,
            'retryable'      => true,
            'transaction_id' => null,
            'message'        => 'Celtiis Cash indisponible (HTTP ' . $status . ')',
        ];
    }

    /**
     * Interroge le statut d'un transfert soumis via PayDunya.
     */
    public function checkStatus(string $referenceId): array
    {
        try {
            $response = Http::baseUrl($this->config('base_url'))
                ->withToken($this->accessToken())
                ->withHeaders([
                    'X-Target-Environment'      => $this->config('target_environment'),
                    'Ocp-Apim-Subscription-Key' => $this->config('token'),
                ])
                ->timeout(30)
                ->get('/disbursement/v1_0/transfer/' . $referenceId);
        } catch (\Throwable $e) {
            return [
                'success'        => false,
                'retryable'      => true,
                'transaction_id' => $referenceId,
                'message'        => 'Celtiis Cash injoignable : ' . $e->getMessage(),
            ];
        }

        if ($response->successful()) {
            $celtiisStatus = strtoupper((string) $response->json('status'));

            return [
                'success'        => $celtiisStatus === 'SUCCESSFUL',
                'retryable'      => $celtiisStatus === 'PENDING',
                'transaction_id' => $response->json('financialTransactionId') ?? $referenceId,
                'message'        => $celtiisStatus,
            ];
        }

        return [
            'success'        => false,
            'retryable'      => $response->status() >= 500 || $response->status() === 429,
            'transaction_id' => $referenceId,
            'message'        => 'Statut Celtiis Cash indisponible (HTTP ' . $response->status() . ')',
        ];
    }

    /**
     * Token OAuth2 PayDunya, mis en cache jusqu'à expiration (env. 1 h).
     *
     * PayDunya utilise une authentification Basic Auth avec
     * master_key comme user et private_key comme password.
     */
    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, 3300, function () {
            $response = Http::baseUrl($this->config('base_url'))
                ->withBasicAuth($this->config('master_key'), $this->config('private_key'))
                ->withHeaders([
                    'Ocp-Apim-Subscription-Key' => $this->config('token'),
                ])
                ->asForm()
                ->post('/disbursement/token/', []);

            if (! $response->ok()) {
                throw new \RuntimeException(
                    'Échec d\'authentification Celtiis Cash (HTTP ' . $response->status() . ')'
                );
            }

            return (string) $response->json('access_token');
        });
    }

    /**
     * UUID v5 déterministe dérivé de la référence FriPay.
     */
    private function referenceIdFor(string $reference): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'fripay-celtiis:' . $reference)->toString();
    }

    private function config(string $key): ?string
    {
        $value = config('fripay.celtiis.' . $key);

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    private function errorBody($response): string
    {
        $body = $response->json();

        return is_array($body) ? json_encode($body) : (string) $response->body();
    }
}
