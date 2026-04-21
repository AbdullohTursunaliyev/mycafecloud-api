<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientToken extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'client_tokens';

    protected $fillable = ['tenant_id','client_id','token_hash','expires_at','last_used_at'];
    protected $casts = ['expires_at'=>'datetime','last_used_at'=>'datetime'];
}
