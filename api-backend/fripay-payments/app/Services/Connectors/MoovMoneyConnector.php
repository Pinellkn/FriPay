<?php

namespace App\Services\Connectors;

use App\Contracts\TransferConnector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;

/**
 * Connecteur Moov Money — API Moov Africa Bénin (Disbursements).
 *
 * Docs : https://moov-africa.bj (portail marchand Moov Africa Bénin)
 * SDK reference : https://github.com/v1p3r75/moov-money-api-php-sdk
 *
 * Flux : token OAuth2 (POST /disbursement/token/) puis envoi du transfert
 * (POST /disbursement/v1_0/transfer). Le statut final arrive par callback
 * HTTP sur X-Callback-Url (SUCCESSFUL / FAILED / PENDING).
 *
 * Idempotence : le X-Reference-Id est un UUID déterministe dérivé de la
 * référence FriPay. Un rejeu de l'outbox réutilise donc le même identifiant :
 * Le réseau renverra une erreur (déjà soumis) et on interroge alors le statut
 * du transfert existant au lieu de le soumettre à nouveau.
 */
class MoovMoneyConnector implements TransferConnector
{
    private const TOKEN_CACHE_KEY = 'moov_money_access_token';

    public function isConfigured(): bool
    {
        return (bool) (
            $this->config('username')
            && $this->config('password')
            && $this->config('encryption_key')
        );
    }

    public function initiateTransfer(array $payload): array
    {
        if (! $this->isConfigured()) {
            return [
                'success'        => false,
                'retryable'      => false,
                'transaction_id' => null,
                'message'        => 'Moov Money non configuré (clés API manquantes)',
            ];
        }

        $referenceId = $this->referenceIdFor($payload['reference']);

        try {
            $response = Http::baseUrl($this->config('base_url'))
                ->withToken($this->accessToken())
                ->withHeaders([
                    'X-Reference-Id'            => $referenceId,
                    'X-Target-Environment'      => $this->config('target_environment'),
                    'Ocp-Apim-Subscription-Key' => $this->config('encryption_key'),
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
                'message'        => 'Moov Money injoignable : ' . $e->getMessage(),
            ];
        }

        $status = $response->status();

        // 200/201/202 : transfert accepté pour traitement.
        if (in_array($status, [200, 201, 202], true)) {
            return [
                'success'        => true,
                'retryable'      => false,
                'transaction_id' => $referenceId,
                'message'        => 'Transfert accepté par Moov Money',
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
                'message'        => 'Moov Money a rejeté le transfert (HTTP ' . $status . ') : ' . $this->errorBody($response),
            ];
        }

        // 429 / 5xx : indisponibilité temporaire -> rejouable.
        return [
            'success'        => false,
            'retryable'      => true,
            'transaction_id' => null,
            'message'        => 'Moov Money indisponible (HTTP ' . $status . ')',
        ];
    }

    /**
     * Interroge le statut d'un transfert soumis à Moov Money.
     */
    public function checkStatus(string $referenceId): array
    {
        try {
            $response = Http::baseUrl($this->config('base_url'))
                ->withToken($this->accessToken())
                ->withHeaders([
                    'X-Target-Environment'      => $this->config('target_environment'),
                    'Ocp-Apim-Subscription-Key' => $this->config('encryption_key'),
                ])
                ->timeout(30)
                ->get('/disbursement/v1_0/transfer/' . $referenceId);
        } catch (\Throwable $e) {
            return [
                'success'        => false,
                'retryable'      => true,
                'transaction_id' => $referenceId,
                'message'        => 'Moov Money injoignable : ' . $e->getMessage(),
            ];
        }

        if ($response->successful()) {
            $moovStatus = strtoupper((string) $response->json('status'));

            return [
                'success'        => $moovStatus === 'SUCCESSFUL',
                'retryable'      => $moovStatus === 'PENDING',
                'transaction_id' => $response->json('financialTransactionId') ?? $referenceId,
                'message'        => $moovStatus,
            ];
        }

        return [
            'success'        => false,
            'retryable'      => $response->status() >= 500 || $response->status() === 429,
            'transaction_id' => $referenceId,
            'message'        => 'Statut Moov Money indisponible (HTTP ' . $response->status() . ')',
        ];
    }

    /**
     * Token OAuth2 Moov Money, mis en cache jusqu'à expiration (env. 1 h).
     *
     * Moov Africa utilise un token AES-256 généré à partir des credentials.
     */
    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, 3300, function () {
            $key = substr($this->config('encryption_key'), 0, 16);

            $token = openssl_encrypt(
                $this->config('username') . '|' . $this->config('password'),
                'aes-256-cbc',
                $key,
                0,
                substr($key, 0, 16)
            );

            if ($token === false) {
                throw new \RuntimeException(
                    'Échec de génération du token Moov Money : ' . openssl_error_string()
                );
            }

            return $token;
        });
    }

    /**
     * UUID v5 déterministe dérivé de la référence FriPay.
     */
    private function referenceIdFor(string $reference): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'fripay-moov:' . $reference)->toString();
    }

    private function config(string $key): ?string
    {
        $value = config('fripay.moov_money.' . $key);

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    private function errorBody($response): string
    {
        $body = $response->json();

        return is_array($body) ? json_encode($body) : (string) $response->body();
    }
}
