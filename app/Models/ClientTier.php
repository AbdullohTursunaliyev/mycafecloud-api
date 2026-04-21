<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientTier extends Model
{
    protected $fillable = [
        'tenant_id','name','slug','min_total','bonus_on_upgrade','color','icon','priority'
    ];

    protected $casts = [
        'min_total' => 'integer',
        'bonus_on_upgrade' => 'integer',
        'priority' => 'integer',
    ];
}

