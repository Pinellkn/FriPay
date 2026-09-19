<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Biller extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'kind', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function payments()
    {
        return $this->hasMany(BillPayment::class);
    }
}
