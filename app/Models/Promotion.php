<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'is_active',
        'days_of_week',
        'time_from',
        'time_to',
        'applies_payment_method',
        'starts_at',
        'ends_at',
        'priority',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'days_of_week' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];
}
