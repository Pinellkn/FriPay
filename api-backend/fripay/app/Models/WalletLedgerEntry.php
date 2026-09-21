<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WalletLedgerEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'wallet_id', 'transaction_id', 'offline_qr_code_id', 'payment_link_id',
        'type', 'amount', 'balance_after', 'reason', 'description', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
