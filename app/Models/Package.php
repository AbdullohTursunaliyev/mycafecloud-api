<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = [
        'tenant_id','name','duration_min','price','zone','is_active'
    ];
}

