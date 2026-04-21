<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantJoinCode extends Model
{
    protected $fillable = ['tenant_id','code','is_active','expires_at'];
    protected $casts = ['expires_at' => 'datetime', 'is_active' => 'boolean'];
}
