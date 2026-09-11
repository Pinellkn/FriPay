<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Corridor extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_operator_id', 'destination_operator_id', 'rail', 'aggregator_provider',
        'priority', 'fee_type', 'fee_value', 'fee_cap', 'min_amount', 'max_amount', 'active',
    ];

    protected function casts(): array
    {
        return [
            'fee_value' => 'decimal:4',
            'fee_cap' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function sourceOperator()
    {
        return $this->belongsTo(Operator::class, 'source_operator_id');
    }

    public function destinationOperator()
    {
        return $this->belongsTo(Operator::class, 'destination_operator_id');
    }
}
