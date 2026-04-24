<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionChargeEvent extends Model
{
    protected $fillable = [
        'tenant_id',
        'session_id',
        'client_id',
        'pc_id',
        'zone_id',
        'source_type',
        'rule_type',
        'rule_id',
        'period_started_at',
        'period_ended_at',
        'billable_units',
        'unit_kind',
        'unit_price',
        'amount',
        'wallet_before',
        'wallet_after',
        'package_before_min',
        'package_after_min',
        'meta',
    ];

    protected $casts = [
        'period_started_at' => 'datetime',
        'period_ended_at' => 'datetime',
        'meta' => 'array',
    ];
}
