<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEFAULT_TENANT_ID = 0;
    private const DEFAULT_NAME = 'Nexora Cloud Default Shell Banner';
    private const DEFAULT_IMAGE_URL = '/defaults/shell-banners/nexora-cloud-shellbanner.png';

    public function up(): void
    {
        DB::table('shell_banners')->updateOrInsert(
            [
                'tenant_id' => self::DEFAULT_TENANT_ID,
                'name' => self::DEFAULT_NAME,
            ],
            [
                'headline' => 'Nexora Cloud',
                'subheadline' => 'Game Shell',
                'body_text' => null,
                'cta_text' => null,
                'prompt_text' => 'Default banner for clubs without uploaded shell banner.',
                'image_url' => self::DEFAULT_IMAGE_URL,
                'logo_url' => self::DEFAULT_IMAGE_URL,
                'audio_url' => null,
                'accent_color' => '#00D5D5',
                'target_scope' => 'all',
                'target_zone_ids' => json_encode([]),
                'target_pc_ids' => json_encode([]),
                'starts_at' => null,
                'ends_at' => null,
                'display_seconds' => 12,
                'sort_order' => 9999,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('shell_banners')
            ->where('tenant_id', self::DEFAULT_TENANT_ID)
            ->where('name', self::DEFAULT_NAME)
            ->delete();
    }
};
