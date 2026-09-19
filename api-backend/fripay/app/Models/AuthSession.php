<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuthSession extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 'refresh_token_hash', 'token_fingerprint', 'device_info', 'ip_address', 'revoked', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'revoked' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
