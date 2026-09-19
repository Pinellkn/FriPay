<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Recharge du wallet (cash-in) via l'agrégateur FeexPay.
 *
 * Cycle de vie :
 *   pending    -> demande créée localement (pas encore soumise / en attente)
 *   processing -> demande de paiement soumise à FeexPay
 *   completed  -> paiement confirmé, wallet crédité (idempotent)
 *   failed     -> paiement refusé / expiré / erreur définitive
 *
 * `provider_reference` = référence de la transaction chez FeexPay.
 * `reference` = référence métier FriPay, transmise à FeexPay comme clé
 * d'idempotence (same reference = same transaction).
 */
class Topup extends Model
{
    use HasUuids;

    protected $table = 'wallet_topups';

    protected $fillable = [
        'user_id',
        'amount',
        'currency',
        'operator_code',
        'phone_number',
        'status',
        'reference',
        'provider_reference',
        'failure_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
