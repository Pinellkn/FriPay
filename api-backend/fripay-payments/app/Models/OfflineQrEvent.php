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

    public function offlineQrCode(): BelongsTo
    {
        return $this->belongsTo(OfflineQrCode::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
