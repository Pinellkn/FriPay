<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'reference', 'idempotency_key', 'sender_user_id', 'sender_account_id',
        'recipient_phone', 'recipient_operator_id', 'recipient_name',
        'amount', 'currency', 'fee_amount', 'total_debited',
        'rail_used', 'aggregator_provider', 'corridor_id', 'status',
        'failure_reason', 'external_reference', 'client_type_snapshot', 'metadata',
        'initiated_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'total_debited' => 'decimal:2',
            'metadata' => 'array',
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderAccount()
    {
        return $this->belongsTo(LinkedAccount::class, 'sender_account_id');
    }

    public function recipientOperator()
    {
        return $this->belongsTo(Operator::class, 'recipient_operator_id');
    }

    public function corridor()
    {
        return $this->belongsTo(Corridor::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(TransactionStatusHistory::class);
    }
}
