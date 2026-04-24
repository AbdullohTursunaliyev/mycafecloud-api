<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShellBanner extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'headline',
        'subheadline',
        'body_text',
        'cta_text',
        'prompt_text',
        'image_url',
        'logo_url',
        'audio_url',
        'accent_color',
        'target_scope',
        'target_zone_ids',
        'target_pc_ids',
        'starts_at',
        'ends_at',
        'display_seconds',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'target_zone_ids' => 'array',
        'target_pc_ids' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'display_seconds' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
