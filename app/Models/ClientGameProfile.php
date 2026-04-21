<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientGameProfile extends Model
{
    protected $fillable = [
        'tenant_id',
        'client_id',
        'game_slug',
        'profile_json',
        'mouse_json',
        'archive_path',
        'archive_size',
        'archive_sha1',
        'version',
        'last_pc_id',
        'last_synced_at',
    ];

    protected $casts = [
        'profile_json' => 'array',
        'mouse_json' => 'array',
        'last_synced_at' => 'datetime',
    ];
}

