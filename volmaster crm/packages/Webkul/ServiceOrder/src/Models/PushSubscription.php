<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dispositivo inscrito para notificações Web Push.
 */
class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    protected $guarded = [];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
