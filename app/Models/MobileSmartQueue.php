<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileSmartQueue extends Model
{
    protected $table = 'mobile_smart_queue';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'zone_key',
        'notify_on_free',
        'status',
        'notified_at',
    ];

    protected $casts = [
        'notify_on_free' => 'boolean',
        'notified_at' => 'datetime',
    ];
}
