<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubVisual extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'headline',
        'subheadline',
        'description_text',
        'prompt_text',
        'display_mode',
        'screen_mode',
        'accent_color',
        'image_url',
        'audio_url',
        'layout_spec',
        'visual_spec',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'layout_spec' => 'array',
        'visual_spec' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
