<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcPairCode extends Model
{
    protected $fillable = ['tenant_id','code','zone','expires_at','used_at','pc_id'];
    protected $casts = ['expires_at'=>'datetime','used_at'=>'datetime'];

    public function pc(){
        return $this->belongsTo(Pc::class);
    }
}

