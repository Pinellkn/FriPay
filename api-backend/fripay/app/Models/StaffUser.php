<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class StaffUser extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;

    protected $fillable = [
        'email', 'password_hash', 'first_name', 'last_name', 'role_id', 'active',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function hasPermission(string $permissionCode): bool
    {
        return $this->role->permissions()->where('code', $permissionCode)->exists();
    }

    /**
     * Find the staff user for authentication via Sanctum.
     */
    public function findForPassport(string $email): ?self
    {
        return static::where('email', $email)->where('active', true)->first();
    }
}
