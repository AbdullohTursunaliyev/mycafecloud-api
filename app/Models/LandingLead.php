<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LandingLead extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'club_name',
        'city',
        'pc_count',
        'plan_code',
        'contact',
        'message',
        'locale',
        'status',
        'meta',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'pc_count' => 'integer',
        'meta' => 'array',
    ];
}
