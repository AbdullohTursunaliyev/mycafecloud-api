<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMembership extends Model
{
    protected $fillable = [
        'identity_id',
        'tenant_id',
        'client_id'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

