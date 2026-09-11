<?php

namespace App\Services;

use App\Contracts\TransferConnector;
use App\Models\Transaction;

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

        // 1. Par fournisseur déclaré sur le corridor (rail / agrégateur).
        //    La casse est normalisée : les clés de config sont en majuscules
        //    (MTN, MOOV, ...) alors que la base peut stocker des minuscules.
        $provider = strtoupper((string) ($transaction->aggregator_provider ?? $transaction->rail_used ?? ''));

        if ($provider && isset($connectors[$provider])) {
            $connector = app($connectors[$provider]);

            if ($connector instanceof TransferConnector) {
                return $connector;
            }
        }

        // 2. Par opérateur destinataire (API native du réseau GSM)
        $operator = strtoupper((string) ($transaction->recipientOperator?->code ?? ''));

        if ($operator && isset($connectors[$operator])) {
            $connector = app($connectors[$operator]);

            if ($connector instanceof TransferConnector) {
                return $connector;
            }
        }

        return null;
    }
}
