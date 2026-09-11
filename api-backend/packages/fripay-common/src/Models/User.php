<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Enums\KycStatus;
use App\Enums\UserStatus;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, HasApiTokens;

    protected $fillable = [
        'phone_number',
        'first_name',
        'last_name',
        'email',
        'pin_hash',
        'kyc_status',
        'client_type',
        'status',
        'preferred_language',
        'last_login_at',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    protected function casts(): array
    {
        return [
            'status'        => UserStatus::class,
            'kyc_status'    => KycStatus::class,
            'last_login_at' => 'datetime',
        ];
    }

    public function linkedAccounts()
    {
        return $this->hasMany(LinkedAccount::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    public function sentTransactions()
    {
        return $this->hasMany(Transaction::class, 'sender_user_id');
    }

    public function authSessions()
    {
        return $this->hasMany(AuthSession::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function primaryAccount()
    {
        return $this->hasOne(LinkedAccount::class)->where('is_primary', true);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }
}
