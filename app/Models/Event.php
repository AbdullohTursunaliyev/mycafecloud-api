<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'tenant_id','type','source','entity_type','entity_id','payload'
    ];
    protected $casts = ['payload'=>'array'];
}

