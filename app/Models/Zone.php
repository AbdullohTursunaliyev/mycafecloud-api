<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Zone extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'price_per_hour',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_per_hour' => 'integer',
    ];
}
