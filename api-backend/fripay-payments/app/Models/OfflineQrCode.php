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
    ];

    protected $casts = [
        'amount'      => 'integer',
        'single_use'  => 'boolean',
        'use_count'   => 'integer',
        'expires_at'  => 'datetime',
        'received_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'metadata'    => 'array',
    ];

    // ── Status constants ──────────────────────────────────────────────
    const STATUS_ACTIVE   = 'active';
    const STATUS_RECEIVED = 'received';
    const STATUS_REDEEMED = 'redeemed';
    const STATUS_EXPIRED  = 'expired';
    const STATUS_REVOKED  = 'revoked';

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

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at->isFuture();
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
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at->isFuture();
    }

    public function isPayable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at->isFuture();
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
        return $query->where('status', self::STATUS_ACTIVE)
                     ->where('expires_at', '>', now());
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
