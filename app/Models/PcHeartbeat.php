<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcHeartbeat extends Model
{
    protected $fillable = ['tenant_id','pc_id','received_at','metrics'];
    protected $casts = ['received_at'=>'datetime','metrics'=>'array'];
}

