<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'phone_number', 'code_hash', 'purpose', 'attempts', 'consumed', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'consumed' => 'boolean',
            'expires_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
