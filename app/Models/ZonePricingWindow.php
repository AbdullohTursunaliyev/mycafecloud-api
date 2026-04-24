<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZonePricingWindow extends Model
{
    protected $fillable = [
        'tenant_id',
        'zone_id',
        'name',
        'starts_at',
        'ends_at',
        'starts_on',
        'ends_on',
        'weekdays',
        'price_per_hour',
        'is_active',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'weekdays' => 'array',
        'is_active' => 'boolean',
        'price_per_hour' => 'integer',
    ];

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }
}
