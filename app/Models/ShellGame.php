<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShellGame extends Model
{
    protected $fillable = [
        'tenant_id',
        'slug',
        'title',
        'category',
        'age_rating',
        'badge',
        'note',
        'launcher',
        'launcher_icon',
        'image_url',
        'hero_url',
        'trailer_url',
        'website_url',
        'help_text',
        'launch_command',
        'launch_args',
        'working_dir',
        'cloud_profile_enabled',
        'config_paths',
        'mouse_hints',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'cloud_profile_enabled' => 'boolean',
        'config_paths' => 'array',
        'mouse_hints' => 'array',
    ];
}
