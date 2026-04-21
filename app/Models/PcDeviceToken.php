<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcDeviceToken extends Model
{
    protected $fillable = ['tenant_id','pc_id','token_hash','last_used_at'];
    protected $casts = ['last_used_at'=>'datetime'];
}

