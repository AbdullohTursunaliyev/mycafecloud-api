<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcCommand extends Model
{
    protected $fillable = [
        'tenant_id','pc_id','batch_id','type','payload','status','sent_at','ack_at','error'
    ];
    protected $casts = ['payload'=>'array','sent_at'=>'datetime','ack_at'=>'datetime'];
}
