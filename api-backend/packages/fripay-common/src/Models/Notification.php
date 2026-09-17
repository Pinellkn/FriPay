<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Notification partagé entre tous les microservices FriPay (table
 * `notifications`, définie par la migration de fripay-users, hébergée sur
 * la même base MySQL que tous les services — cf. fripay-common/README).
 *
 * Contrairement à fripay-users\App\Models\UserNotification (qui reste en
 * place pour NotificationController, lecture seule), ce modèle permet à
 * n'importe quel service (fripay-payments notamment) de CRÉER une
 * notification directement, sans appel HTTP inter-service.
 */
class Notification extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'type', 'channel', 'title', 'body', 'related_transaction_id', 'read',
    ];

    protected function casts(): array
    {
        return [
            'read' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
