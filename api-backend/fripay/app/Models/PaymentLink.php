<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * FriPay Link — lien de paiement partageable (montant verrouillé, token
 * public non-devinable), payable par n'importe qui via FeexPay sans compte.
 *
 * Cycle de vie :
 *   created   -> lien créé, en attente de paiement
 *   paid      -> paiement confirmé, wallet du créateur crédité (idempotent)
 *   expired   -> date d'expiration dépassée sans paiement
 *   cancelled -> annulé par le créateur
 */
class PaymentLink extends Model
{
    use HasUuids;

    public const STATUS_CREATED   = 'created';
    public const STATUS_PAID      = 'paid';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    /** Durée de validité par défaut d'un lien. */
    public const DEFAULT_TTL_HOURS = 48;

    protected $fillable = [
        'user_id',
        'token',
        'amount',
        'currency',
        'description',
        'status',
        'provider_reference',
        'expires_at',
        'payer_phone',
        'payer_operator',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at'    => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPayable(): bool
    {
        return $this->status === self::STATUS_CREATED && ! $this->isExpired();
    }

    /**
     * Nom d'affichage du créateur pour la page publique —
     * vie privée : jamais le numéro complet.
     */
    public function creatorDisplayName(): string
    {
        $user = $this->user;

        if (! $user) {
            return 'Utilisateur FriPay';
        }

        $first = trim((string) $user->first_name);
        $lastInitial = mb_strtoupper(mb_substr(trim((string) $user->last_name), 0, 1));

        if ($first !== '' && $lastInitial !== '') {
            return trim($first . ' ' . $lastInitial . '.');
        }

        // Repli : dernier bloc du numéro, partiellement masqué.
        $phone = (string) $user->phone_number;

        return substr($phone, 0, max(0, strlen($phone) - 4)) . '****';
    }

    /**
     * Marque le lien comme payé (idempotent). Retourne true si CET appel a
     * effectué la transition, false si le lien était déjà payé/annulé/expiré.
     */
    public function markPaid(string $payerPhone, ?string $payerOperator): bool
    {
        if ($this->status !== self::STATUS_CREATED) {
            return false;
        }

        return $this->forceFill([
            'status'          => self::STATUS_PAID,
            'payer_phone'     => $payerPhone,
            'payer_operator'  => $payerOperator,
            'paid_at'         => now(),
        ])->save();
    }
}
