<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhonePrefix extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'operator_id', 'prefix', 'country_code',
    ];

    public function operator()
    {
        return $this->belongsTo(Operator::class);
    }
}
