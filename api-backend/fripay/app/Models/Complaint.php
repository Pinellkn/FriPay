<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    use HasUuids;

    protected $fillable = [
        'reference', 'user_id', 'reason', 'subject', 'description',
        'status', 'linked_transaction_id', 'refund_requested',
        'refund_status', 'refund_amount',
    ];

    protected function casts(): array
    {
        return [
            'refund_requested' => 'boolean',
            'refund_amount' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function linkedTransaction()
    {
        return $this->belongsTo(Transaction::class, 'linked_transaction_id');
    }

    /**
     * Motifs qui donnent droit à une demande de remboursement automatique
     * lors de la création (miroir de `ticketReasons` côté mobile).
     */
    public const REFUND_ELIGIBLE_REASONS = [
        'wrong_transfer', 'not_received', 'duplicate_charge',
    ];
}
