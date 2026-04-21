<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcShellGame extends Model
{
    protected $fillable = [
        'tenant_id',
        'pc_id',
        'shell_game_id',
        'is_installed',
        'version',
        'last_seen_at',
        'last_error',
    ];

    protected $casts = [
        'is_installed' => 'boolean',
        'last_seen_at' => 'datetime',
    ];
}

