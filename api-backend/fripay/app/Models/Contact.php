<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 'contact_phone', 'contact_name', 'detected_operator_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function detectedOperator()
    {
        return $this->belongsTo(Operator::class, 'detected_operator_id');
    }
}
