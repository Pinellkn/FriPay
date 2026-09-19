<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineQrCode extends Model
{
    protected $fillable = [
        'uuid',
        'sender_user_id',
        'amount',
        'currency',
        'sender_public_key',
        'signature',
        'qr_payload',
        'status',
        'qr_mode',
        'qr_type',
        'recipient_user_id',
        'merchant_user_id',
        'description',
        'single_use',
        'use_count',
        'received_at',
        'redeemed_at',
        'expires_at',
        'idempotency_key',
        'metadata',
        'recipient_phone',
        'has_recipient_account',
        'external_validation_code',
        'external_attempts',
        'external_max_attempts',
        'external_payout_number',
        'external_payout_network',
        'external_claimed_at',
        'held_at',
        'settled_at',
        'refunded_at',
    ];

    protected $casts = [
        'amount'                 => 'integer',
        'single_use'             => 'boolean',
        'use_count'              => 'integer',
        'expires_at'             => 'datetime',
        'received_at'            => 'datetime',
        'redeemed_at'            => 'datetime',
        'metadata'               => 'array',
        'has_recipient_account'  => 'boolean',
        'external_attempts'      => 'integer',
        'external_max_attempts'  => 'integer',
        'external_claimed_at'    => 'datetime',
        'held_at'                => 'datetime',
        'settled_at'             => 'datetime',
        'refunded_at'            => 'datetime',
    ];

    // ── Status constants ──────────────────────────────────────────────
    const STATUS_ACTIVE    = 'active';
    const STATUS_RECEIVED  = 'received';
    const STATUS_REDEEMED  = 'redeemed';
    const STATUS_EXPIRED   = 'expired';
    const STATUS_REVOKED   = 'revoked';
    const STATUS_CANCELLED = 'cancelled';

    // ── QR Mode constants ─────────────────────────────────────────────
    const MODE_CPM = 'cpm'; // Customer Present Mode — marchand scanne le client
    const MODE_MPM = 'mpm'; // Merchant Present Mode — client scanne le marchand

    // ── QR Type constants ─────────────────────────────────────────────
    const TYPE_STATIC  = 'static';  // QR fixe (identité du marchand, montant saisi manuellement)
    const TYPE_DYNAMIC = 'dynamic'; // QR dynamique (montant pré-rempli, expire)

    // ── Relationships ─────────────────────────────────────────────────

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }

    public function events()
    {
        return $this->hasMany(OfflineQrEvent::class);
    }

    // ── State helpers ─────────────────────────────────────────────────

    /**
     * expires_at à null = QR "argent" P2P (§6.b) : aucun délai imposé,
     * il reste valable tant qu'il n'est pas réclamé, révoqué ou annulé.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && !$this->hasExpired();
    }

    public function isRedeemable(): bool
    {
        // Un QR déjà reçu est TOUJOURS encaissable, même expiré.
        // L'expiration ne doit bloquer que les nouvelles actions,
        // pas le remboursement d'argent déjà stocké.
        if ($this->status === self::STATUS_RECEIVED) {
            return true;
        }

        // Un QR actif n'est encaissable que s'il n'a pas expiré
        return $this->status === self::STATUS_ACTIVE && !$this->hasExpired();
    }

    public function isPayable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && !$this->hasExpired();
    }

    // ── Parcours receveur externe (§6.e) ──────────────────────────────

    public function isExternal(): bool
    {
        return $this->has_recipient_account === false;
    }

    public function externalAttemptsRemaining(): int
    {
        return max(0, $this->external_max_attempts - $this->external_attempts);
    }

    public function isExternallyClaimable(): bool
    {
        return $this->isExternal()
            && in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_RECEIVED], true)
            && !$this->hasExpired()
            && $this->externalAttemptsRemaining() > 0;
    }

    // ── Mode/Type helpers ─────────────────────────────────────────────

    public function isCpm(): bool
    {
        return $this->qr_mode === self::MODE_CPM;
    }

    public function isMpm(): bool
    {
        return $this->qr_mode === self::MODE_MPM;
    }

    public function isStatic(): bool
    {
        return $this->qr_type === self::TYPE_STATIC;
    }

    public function isDynamic(): bool
    {
        return $this->qr_type === self::TYPE_DYNAMIC;
    }

    public function isMerchantQr(): bool
    {
        return $this->merchant_user_id !== null;
    }

    /**
     * Vérifie que la clé publique fournie (telle qu'extraite d'un payload
     * scanné, en base64) correspond à celle enregistrée à la génération
     * du QR — liaison clé publique ↔ compte expéditeur (fix audit M3).
     */
    public function hasPublicKey(string $payloadPubKeyB64): bool
    {
        if ($payloadPubKeyB64 === '' || $this->sender_public_key === null) {
            return false;
        }

        return hash_equals($this->sender_public_key, $payloadPubKeyB64);
    }

    // ── Increment use count (pour QR à usage unique) ──────────────────

    public function recordUse(): void
    {
        $this->increment('use_count');

        if ($this->single_use) {
            $this->update(['status' => self::STATUS_REDEEMED]);
        }
    }

    // ── Scopes ────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        // expires_at peut être NULL depuis §6.b (pas de délai imposé pour les
        // QR argent P2P) : NULL n'est jamais > now() en SQL, donc il faut
        // explicitement inclure les QR sans expiration.
        return $query->where('status', self::STATUS_ACTIVE)
                     ->where(function ($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }

    public function scopeMerchantQr($query)
    {
        return $query->whereNotNull('merchant_user_id');
    }

    public function scopeForMerchant($query, string $merchantId)
    {
        return $query->where('merchant_user_id', $merchantId);
    }
}
