<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientHubProfile extends Model
{
    protected $fillable = [
        'tenant_id',
        'client_id',
        'recent_json',
        'favorites_json',
        'last_pc_code',
        'version',
    ];

    protected $casts = [
        'recent_json' => 'array',
        'favorites_json' => 'array',
    ];
}

