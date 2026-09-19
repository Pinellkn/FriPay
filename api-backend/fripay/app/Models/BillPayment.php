<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillPayment extends Model
{
    use HasUuids;

    protected $fillable = [
        'reference', 'user_id', 'biller_id', 'subscriber_reference',
        'amount', 'status', 'wallet_ledger_entry_id', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function biller()
    {
        return $this->belongsTo(Biller::class);
    }
}
