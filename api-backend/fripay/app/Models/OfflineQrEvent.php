<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineQrEvent extends Model
{
    protected $fillable = [
        'offline_qr_code_id',
        'event_type',
        'actor_user_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    // Event type constants
    const EVENT_GENERATED              = 'generated';
    const EVENT_SCANNED                = 'scanned';
    const EVENT_RECEIVED               = 'received';
    const EVENT_REDEEMED               = 'redeemed';
    const EVENT_REVOKED                = 'revoked';
    const EVENT_RECONCILIATION_OK      = 'reconciliation_ok';
    const EVENT_RECONCILIATION_DOUBLE  = 'reconciliation_double_spend';
    const EVENT_HELD                   = 'held';
    const EVENT_SETTLED                = 'settled';
    const EVENT_EXTERNAL_CODE_ISSUED   = 'external_code_issued';
    const EVENT_EXTERNAL_ATTEMPT_FAILED = 'external_attempt_failed';
    const EVENT_EXTERNAL_CLAIMED       = 'external_claimed';
    const EVENT_CANCELLED_REFUNDED     = 'cancelled_refunded';

    public function offlineQrCode(): BelongsTo
    {
        return $this->belongsTo(OfflineQrCode::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
