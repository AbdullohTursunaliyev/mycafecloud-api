<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenants') || !Schema::hasTable('shell_games')) {
            return;
        }

        $now = now();
        $rows = $this->gameRows();
        $hasCloudProfileColumn = Schema::hasColumn('shell_games', 'cloud_profile_enabled');
        $hasConfigPathsColumn = Schema::hasColumn('shell_games', 'config_paths');
        $hasMouseHintsColumn = Schema::hasColumn('shell_games', 'mouse_hints');

        DB::table('tenants')
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($tenantId) use ($rows, $now, $hasCloudProfileColumn, $hasConfigPathsColumn, $hasMouseHintsColumn) {
                $hasCatalog = DB::table('shell_games')
                    ->where('tenant_id', $tenantId)
                    ->exists();

                if (!$hasCatalog) {
                    return;
                }

                foreach ($rows as $row) {
                    $payload = array_merge($row, [
                        'tenant_id' => $tenantId,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if (!$hasCloudProfileColumn) {
                        unset($payload['cloud_profile_enabled']);
                    }
                    if (!$hasConfigPathsColumn) {
                        unset($payload['config_paths']);
                    }
                    if (!$hasMouseHintsColumn) {
                        unset($payload['mouse_hints']);
                    }

                    DB::table('shell_games')->updateOrInsert(
                        [
                            'tenant_id' => $tenantId,
                            'slug' => $row['slug'],
                        ],
                        $payload
                    );
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shell_games')) {
            return;
        }

        DB::table('shell_games')
            ->whereIn('slug', ['stumble-guys', 'iccup'])
            ->delete();
    }

    private function gameRows(): array
    {
        return [
            [
                'slug' => 'stumble-guys',
                'title' => 'Stumble Guys',
                'category' => 'action',
                'age_rating' => '7+',
                'badge' => 'Free',
                'note' => 'Party royale',
                'launcher' => 'Steam',
                'launcher_icon' => 'ST',
                'image_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/1677740/header.jpg',
                'hero_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/1677740/library_hero.jpg',
                'trailer_url' => null,
                'website_url' => 'https://www.stumbleguys.com',
                'help_text' => 'Use your Steam account.',
                'launch_command' => 'steam://rungameid/1677740',
                'launch_args' => null,
                'working_dir' => null,
                'cloud_profile_enabled' => false,
                'config_paths' => json_encode([]),
                'mouse_hints' => json_encode(['vendors' => ['generic']]),
                'sort_order' => 65,
            ],
            [
                'slug' => 'iccup',
                'title' => 'ICCup Launcher',
                'category' => 'strategy',
                'age_rating' => '12+',
                'badge' => 'LAN',
                'note' => 'Warcraft III / DotA',
                'launcher' => 'ICCup',
                'launcher_icon' => 'IC',
                'image_url' => null,
                'hero_url' => null,
                'trailer_url' => null,
                'website_url' => 'https://iccup.com',
                'help_text' => 'ICCup launcher opens the installed Warcraft III / DotA client.',
                'launch_command' => 'C:\\Program Files (x86)\\ICCup\\Launcher\\Launcher.exe',
                'launch_args' => null,
                'working_dir' => 'C:\\Program Files (x86)\\ICCup\\Launcher',
                'cloud_profile_enabled' => false,
                'config_paths' => json_encode([]),
                'mouse_hints' => json_encode(['vendors' => ['generic']]),
                'sort_order' => 90,
            ],
        ];
    }
};
