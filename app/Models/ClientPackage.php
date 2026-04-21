<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientPackage extends Model
{
    protected $fillable = [
        'tenant_id','client_id','package_id',
        'remaining_min','expires_at','status'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }


}

