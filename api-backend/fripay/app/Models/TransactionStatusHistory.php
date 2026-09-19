<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionStatusHistory extends Model
{
    use HasFactory;

    // La table est au singulier (voir migration create_transaction_status_history_table).
    protected $table = 'transaction_status_history';

    protected $fillable = [
        'transaction_id', 'previous_status', 'new_status', 'source', 'note',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
