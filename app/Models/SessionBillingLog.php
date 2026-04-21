<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionBillingLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'session_id',
        'client_id',
        'pc_id',
        'mode',
        'minutes',
        'amount',
        'price_per_hour',
        'price_per_min',
        'balance_before',
        'bonus_before',
        'balance_after',
        'bonus_after',
        'remaining_min_before',
        'remaining_min_after',
        'reason',
    ];
}
