<?php

namespace App\Contracts;

/**
 * Connecteur de paiement mobile money.
 *
 * Chaque réseau GSM (MTN, Moov, Celtiis) dispose d'une API native. Chaque
 * intégration implémente cette interface puis est déclarée dans
 * `config/fripay.php` (section `connectors`).
 *
 * Tant qu'aucun connecteur n'est enregistré, les transferts acceptés sont
 * conservés en file d'attente locale (outbox) et exécutés dès qu'un
 * connecteur devient disponible.
 *
 * IMPORTANT — idempotence : en cas de réponse perdue (le réseau traite le
 * transfert mais un timeout coupe la réponse), l'outbox relancera
 * `initiateTransfer` avec la même `reference`. Chaque implémentation DOIT
 * garantir que `reference` sert de clé d'idempotence auprès de l'API du
 * réseau (même transaction réutilisée, pas de nouveau débit), sinon un
 * retry peut provoquer un double paiement.
 */
interface TransferConnector
{
    /**
     * Le connecteur est-il configuré (clés API présentes) ?
     */
    public function isConfigured(): bool;

    /**
     * Initie un transfert sortant (payout) vers un compte mobile money.
     *
     * @param array $payload [
     *   'amount'          => int (montant en XOF),
     *   'recipient_phone' => string (format E.164),
     *   'reference'       => string (référence métier FriPay, clé d'idempotence),
     *   'operator_code'   => string (MTN|MOOV|CELTIIS),
     *   'description'     => string (optionnel),
     * ]
     *
     * @return array [
     *   'success'         => bool,
     *   'retryable'       => bool (true si erreur réseau/5xx/429 -> rejouable),
     *   'transaction_id'  => string|null (identifiant chez le réseau),
     *   'message'         => string,
     * ]
     */
    public function initiateTransfer(array $payload): array;
}
