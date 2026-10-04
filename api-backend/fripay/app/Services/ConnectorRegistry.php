<?php

namespace App\Services;

use App\Contracts\TransferConnector;
use App\Models\Transaction;
use App\Services\Connectors\FeexpayPayoutConnector;

/**
 * Résout le connecteur adapté à une transaction.
 *
 * Les API natives de chaque réseau GSM (MTN, Moov, Celtiis) seront
 * implémentées puis déclarées dans `config/fripay.php` (section
 * `connectors`). La résolution se fait d'abord par fournisseur du corridor
 * (agrégateur), puis par opérateur destinataire (API native du réseau).
 */
class ConnectorRegistry
{
    /**
     * Retourne le connecteur à utiliser pour cette transaction, ou null si
     * aucun n'est enregistré / activé.
     */
    public function resolve(Transaction $transaction): ?TransferConnector
    {
        $connectors = config('fripay.connectors', []);

        // Connecteur natif résolu mais non configuré (clés API vides) :
        // gardé de côté pour le repli agrégateur ci-dessous.
        $candidate = null;

        // 1. Par fournisseur déclaré sur le corridor (rail / agrégateur).
        //    La casse est normalisée : les clés de config sont en majuscules
        //    (MTN, MOOV, ...) alors que la base peut stocker des minuscules.
        $provider = strtoupper((string) ($transaction->aggregator_provider ?? $transaction->rail_used ?? ''));

        if ($provider && isset($connectors[$provider])) {
            $connector = app($connectors[$provider]);

            if ($connector instanceof TransferConnector) {
                if ($connector->isConfigured()) {
                    return $connector;
                }

                $candidate = $connector;
            }
        }

        // 2. Par opérateur destinataire (API native du réseau GSM)
        $operator = strtoupper((string) ($transaction->recipientOperator?->code ?? ''));

        if ($operator && isset($connectors[$operator])) {
            $connector = app($connectors[$operator]);

            if ($connector instanceof TransferConnector) {
                if ($connector->isConfigured()) {
                    return $connector;
                }

                $candidate = $connector;
            }
        }

        // 3. Repli agrégateur FeexPay : si aucun connecteur natif n'est
        //    configuré (cas actuel — clés MTN/Moov/Celtiis vides) et que le
        //    payout FeexPay est disponible (identifiants marchands présents),
        //    les envois partent via l'agrégateur. Les API natives reprennent
        //    automatiquement la main le jour où leurs clés sont renseignées.
        if (! $candidate?->isConfigured()) {
            $feexpay = app(FeexpayPayoutConnector::class);

            if ($feexpay instanceof TransferConnector && $feexpay->isConfigured()) {
                return $feexpay;
            }
        }

        return $candidate;
    }
}
