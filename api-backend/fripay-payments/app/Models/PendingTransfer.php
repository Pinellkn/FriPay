<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * File d'attente des transferts différés (outbox).
 *
 * Un enregistrement est créé quand un transfert est accepté alors que le
 * connecteur du réseau (API opérateur ou agrégateur) est indisponible ou
 * pas encore intégré. La commande `transfers:process-pending` (ou le flush
 * opportuniste sur GET /transfers) le traite dès qu'un connecteur est
 * disponible, avec backoff exponentiel.
 */
class PendingTransfer extends Model
{
    use HasUuids;

    protected $fillable = [
        'transaction_id', 'payload', 'status', 'attempts', 'max_attempts',
        'next_retry_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload'       => 'array',
            'attempts'      => 'integer',
            'max_attempts'  => 'integer',
            'next_retry_at' => 'datetime',
        ];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
