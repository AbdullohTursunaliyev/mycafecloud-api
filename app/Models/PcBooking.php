<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcBooking extends Model
{
    protected $fillable = ['tenant_id', 'pc_id', 'client_id', 'reserved_from', 'reserved_until'];
    protected $casts = [
        'reserved_from' => 'datetime',
        'reserved_until' => 'datetime',
    ];
}
