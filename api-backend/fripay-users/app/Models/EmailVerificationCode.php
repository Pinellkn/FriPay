<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailVerificationCode extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 'email', 'code_hash', 'attempts', 'consumed', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'consumed' => 'boolean',
            'expires_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
