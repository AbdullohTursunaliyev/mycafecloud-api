<?php

namespace App\Services;

use App\Models\ClientGameProfile;
use App\Models\Pc;
use App\Models\PcShellGame;
use App\Models\ShellGame;
use Illuminate\Validation\ValidationException;

class ShellGameCatalogService
{
    public function publicCatalog(int $tenantId, string $pcCode = '', int $clientId = 0): array
    {
        $pc = null;
        if ($pcCode !== '') {
            $pc = Pc::query()
                ->where('tenant_id', $tenantId)
                ->where('code', $pcCode)
                ->first();
        }

        $this->ensureDefaultCatalog($tenantId);

        $games = ShellGame::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $states = collect();
        if ($pc) {
            $states = PcShellGame::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $pc->id)
                ->get()
                ->keyBy('shell_game_id');
        }

        $profilesBySlug = collect();
        if ($clientId > 0) {
            $profilesBySlug = ClientGameProfile::query()
                ->where('tenant_id', $tenantId)
                ->where('client_id', $clientId)
                ->get(['game_slug', 'version', 'updated_at', 'archive_path', 'mouse_json'])
                ->keyBy('game_slug');
        }

        return [
            'data' => $games->map(function (ShellGame $game) use ($states, $profilesBySlug) {
                $state = $states->get($game->id);
                $profile = $profilesBySlug->get($game->slug);
                $defaultHints = $this->defaultCloudHints((string) $game->slug);
                $configPaths = is_array($game->config_paths) ? $game->config_paths : ($defaultHints['config_paths'] ?? []);
                $mouseHints = is_array($game->mouse_hints) ? $game->mouse_hints : ($defaultHints['mouse_hints'] ?? []);
                $profileMouse = (is_object($profile) || is_array($profile)) && is_array($profile->mouse_json ?? null)
                    ? $profile->mouse_json
                    : null;

                return [
                    'id' => $game->slug,
                    'slug' => $game->slug,
                    'name' => $game->title,
                    'cat' => $game->category,
                    'age' => $game->age_rating,
                    'badge' => $game->badge,
                    'note' => $game->note,
                    'launcher' => $game->launcher,
                    'launcherIcon' => $game->launcher_icon,
                    'img' => $game->image_url,
                    'hero' => $game->hero_url,
                    'trailer' => $game->trailer_url,
                    'website' => $game->website_url,
                    'help' => $game->help_text,
                    'launch' => [
                        'command' => $game->launch_command,
                        'args' => $game->launch_args,
                        'working_dir' => $game->working_dir,
                    ],
                    'install' => [
                        'is_installed' => $state ? (bool) $state->is_installed : null,
                        'version' => $state?->version,
                        'last_seen_at' => optional($state?->last_seen_at)->toIso8601String(),
                        'last_error' => $state?->last_error,
                    ],
                    'cloud_profile' => [
                        'enabled' => (bool) ($game->cloud_profile_enabled ?? true),
                        'config_paths' => $configPaths,
                        'mouse_hints' => $mouseHints,
                        'exists' => (bool) $profile,
                        'version' => $profile ? (int) $profile->version : null,
                        'has_archive' => $profile ? !empty($profile->archive_path) : false,
                        'updated_at' => optional($profile?->updated_at)->toIso8601String(),
                        'mouse_profile' => $profileMouse,
                    ],
                ];
            })->values()->all(),
            'meta' => [
                'pc_found' => (bool) $pc,
                'pc_code' => $pc?->code,
            ],
        ];
    }

    public function adminCatalog(int $tenantId, ?int $pcId = null): array
    {
        $this->ensureDefaultCatalog($tenantId);

        $pc = null;
        if ($pcId && $pcId > 0) {
            $pc = Pc::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $pcId)
                ->first();
        }

        $rows = ShellGame::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $states = collect();
        if ($pc) {
            $states = PcShellGame::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $pc->id)
                ->get()
                ->keyBy('shell_game_id');
        }

        return [
            'data' => $rows->map(function (ShellGame $row) use ($states) {
                $state = $states->get($row->id);

                return array_merge($row->toArray(), [
                    'install' => [
                        'is_installed' => $state ? (bool) $state->is_installed : null,
                        'version' => $state?->version,
                        'last_seen_at' => optional($state?->last_seen_at)->toIso8601String(),
                        'last_error' => $state?->last_error,
                    ],
                ]);
            })->values()->all(),
            'meta' => [
                'pc_id' => $pc?->id,
                'pc_found' => (bool) $pc,
            ],
        ];
    }

    public function create(int $tenantId, array $payload): ShellGame
    {
        $payload['tenant_id'] = $tenantId;

        return ShellGame::query()->create($payload);
    }

    public function update(int $tenantId, int $id, array $payload): ShellGame
    {
        $row = ShellGame::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $row->fill($payload)->save();

        return $row->fresh();
    }

    public function toggle(int $tenantId, int $id): array
    {
        $row = ShellGame::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $row->is_active = !$row->is_active;
        $row->save();

        return [
            'id' => (int) $row->id,
            'is_active' => (bool) $row->is_active,
        ];
    }

    public function setPcState(int $tenantId, int $pcId, int $gameId, array $payload): PcShellGame
    {
        $pc = Pc::query()->where('tenant_id', $tenantId)->findOrFail($pcId);
        $game = ShellGame::query()->where('tenant_id', $tenantId)->findOrFail($gameId);

        return PcShellGame::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'pc_id' => $pc->id,
                'shell_game_id' => $game->id,
            ],
            [
                'is_installed' => (bool) $payload['is_installed'],
                'version' => $payload['version'] ?? null,
                'last_error' => $payload['last_error'] ?? null,
                'last_seen_at' => now(),
            ]
        );
    }

    private function defaultCloudHints(string $slug): array
    {
        return match ($slug) {
            'cs2' => [
                'config_paths' => [
                    '%STEAM%/userdata/%STEAM_ID%/730/local/cfg',
                    '%STEAM%/steamapps/common/Counter-Strike Global Offensive/game/csgo/cfg',
                ],
                'mouse_hints' => [
                    'vendors' => ['logitech', 'razer', 'generic'],
                    'logitech_profile' => 'MyCafeCloud-CS2',
                    'razer_profile' => 'MyCafeCloud-CS2',
                ],
            ],
            'dota2' => [
                'config_paths' => [
                    '%STEAM%/userdata/%STEAM_ID%/570/remote/cfg',
                    '%STEAM%/steamapps/common/dota 2 beta/game/dota/cfg',
                ],
                'mouse_hints' => [
                    'vendors' => ['logitech', 'razer', 'generic'],
                    'logitech_profile' => 'MyCafeCloud-Dota2',
                    'razer_profile' => 'MyCafeCloud-Dota2',
                ],
            ],
            default => [
                'config_paths' => [],
                'mouse_hints' => ['vendors' => ['logitech', 'razer', 'generic']],
            ],
        };
    }

    private function ensureDefaultCatalog(int $tenantId): void
    {
        if (ShellGame::query()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        foreach ($this->defaultCatalogRows() as $row) {
            ShellGame::query()->create(array_merge($row, [
                'tenant_id' => $tenantId,
                'is_active' => true,
            ]));
        }
    }

    private function defaultCatalogRows(): array
    {
        return [
            [
                'slug' => 'dota2',
                'title' => 'Dota 2',
                'category' => 'moba',
                'age_rating' => '12+',
                'note' => 'MOBA',
                'launcher' => 'Steam',
                'launcher_icon' => 'ST',
                'image_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/570/header.jpg',
                'hero_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/570/page_bg_generated_v6b.jpg',
                'trailer_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/256705156/movie480.mp4',
                'website_url' => 'https://www.dota2.com/home',
                'help_text' => 'Use your Steam account.',
                'launch_command' => 'steam://rungameid/570',
                'cloud_profile_enabled' => true,
                'config_paths' => [
                    '%STEAM%/userdata/%STEAM_ID%/570/remote/cfg',
                    '%STEAM%/steamapps/common/dota 2 beta/game/dota/cfg',
                ],
                'mouse_hints' => [
                    'vendors' => ['logitech', 'razer', 'generic'],
                    'logitech_profile' => 'MyCafeCloud-Dota2',
                    'razer_profile' => 'MyCafeCloud-Dota2',
                ],
                'sort_order' => 10,
            ],
            [
                'slug' => 'cs2',
                'title' => 'Counter-Strike 2',
                'category' => 'shooter',
                'age_rating' => '16+',
                'badge' => 'Hot',
                'note' => 'Competitive FPS',
                'launcher' => 'Steam',
                'launcher_icon' => 'ST',
                'image_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/730/header.jpg',
                'hero_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/730/page_bg_generated_v6b.jpg',
                'trailer_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/256705156/movie480.mp4',
                'website_url' => 'https://www.counter-strike.net/cs2',
                'help_text' => 'Prime and protected account recommended.',
                'launch_command' => 'steam://rungameid/730',
                'cloud_profile_enabled' => true,
                'config_paths' => [
                    '%STEAM%/userdata/%STEAM_ID%/730/local/cfg',
                    '%STEAM%/steamapps/common/Counter-Strike Global Offensive/game/csgo/cfg',
                ],
                'mouse_hints' => [
                    'vendors' => ['logitech', 'razer', 'generic'],
                    'logitech_profile' => 'MyCafeCloud-CS2',
                    'razer_profile' => 'MyCafeCloud-CS2',
                ],
                'sort_order' => 20,
            ],
            [
                'slug' => 'valorant',
                'title' => 'Valorant',
                'category' => 'shooter',
                'age_rating' => '16+',
                'note' => 'Tactical shooter',
                'launcher' => 'Riot',
                'launcher_icon' => 'RT',
                'image_url' => 'https://images.contentstack.io/v3/assets/bltb6530b271fddd0b1/blt53cb66481082f6f6/5ecb8f8f6c7ba6504f0f91a0/VALORANT_Logo_V.jpg',
                'hero_url' => 'https://images.contentstack.io/v3/assets/bltb6530b271fddd0b1/blta58f50f9c50e6d35/650cabf7f7f1a2dbf7f8c08f/Val_5_08_patchnotes_Banner.jpg',
                'website_url' => 'https://playvalorant.com',
                'help_text' => 'Use your Riot account.',
                'sort_order' => 30,
            ],
            [
                'slug' => 'fortnite',
                'title' => 'Fortnite',
                'category' => 'shooter',
                'age_rating' => '12+',
                'note' => 'Battle royale',
                'launcher' => 'Epic',
                'launcher_icon' => 'EP',
                'image_url' => 'https://cdn2.unrealengine.com/fortnite-og-social-image-1200x630-4f8f9e6d6a6c.jpg',
                'hero_url' => 'https://cdn2.unrealengine.com/fortnite-chapter-5-season-2-1920x1080-09f5f78f7c0d.jpg',
                'website_url' => 'https://www.fortnite.com',
                'help_text' => 'Use your Epic account.',
                'sort_order' => 40,
            ],
            [
                'slug' => 'apex',
                'title' => 'Apex Legends',
                'category' => 'shooter',
                'age_rating' => '16+',
                'note' => 'Team BR',
                'launcher' => 'EA App',
                'launcher_icon' => 'EA',
                'image_url' => 'https://media.contentapi.ea.com/content/dam/apex-legends/common/apex-grid-tile-logo-4x3.jpg.adapt.crop16x9.431p.jpg',
                'hero_url' => 'https://media.contentapi.ea.com/content/dam/apex-legends/common/apex-hero-medium-7x2-xl.jpg.adapt.crop7x2.1920w.jpg',
                'website_url' => 'https://www.ea.com/games/apex-legends',
                'help_text' => 'Use your EA account.',
                'sort_order' => 50,
            ],
            [
                'slug' => 'pubg',
                'title' => 'PUBG Battlegrounds',
                'category' => 'shooter',
                'age_rating' => '16+',
                'badge' => 'Free',
                'note' => 'Battle royale',
                'launcher' => 'Steam',
                'launcher_icon' => 'ST',
                'image_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/578080/header.jpg',
                'hero_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/578080/page_bg_generated_v6b.jpg',
                'website_url' => 'https://pubg.com',
                'help_text' => 'Steam account and anti-cheat required.',
                'launch_command' => 'steam://rungameid/578080',
                'sort_order' => 60,
            ],
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
                'website_url' => 'https://www.stumbleguys.com',
                'help_text' => 'Use your Steam account.',
                'launch_command' => 'steam://rungameid/1677740',
                'cloud_profile_enabled' => false,
                'config_paths' => [],
                'mouse_hints' => [
                    'vendors' => ['generic'],
                ],
                'sort_order' => 65,
            ],
            [
                'slug' => 'gta5',
                'title' => 'Grand Theft Auto V',
                'category' => 'rpg',
                'age_rating' => '18+',
                'note' => 'Open world',
                'launcher' => 'Rockstar',
                'launcher_icon' => 'RS',
                'image_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/271590/header.jpg',
                'hero_url' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/271590/page_bg_generated_v6b.jpg',
                'website_url' => 'https://www.rockstargames.com/gta-v',
                'help_text' => 'Use your Rockstar account.',
                'sort_order' => 70,
            ],
            [
                'slug' => 'lol',
                'title' => 'League of Legends',
                'category' => 'moba',
                'age_rating' => '12+',
                'note' => 'MOBA',
                'launcher' => 'Riot',
                'launcher_icon' => 'RT',
                'image_url' => 'https://cmsassets.rgpub.io/sanity/images/dsfx7636/news_live/8578d0de4f15ad68e9a91be17f5ae95db7fdf540-1920x1080.jpg',
                'hero_url' => 'https://cmsassets.rgpub.io/sanity/images/dsfx7636/news_live/8578d0de4f15ad68e9a91be17f5ae95db7fdf540-1920x1080.jpg',
                'website_url' => 'https://www.leagueoflegends.com',
                'help_text' => 'Use your Riot account.',
                'sort_order' => 80,
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
                'website_url' => 'https://iccup.com',
                'help_text' => 'ICCup launcher opens the installed Warcraft III / DotA client.',
                'launch_command' => 'C:\\Program Files (x86)\\ICCup\\Launcher\\Launcher.exe',
                'working_dir' => 'C:\\Program Files (x86)\\ICCup\\Launcher',
                'cloud_profile_enabled' => false,
                'config_paths' => [],
                'mouse_hints' => [
                    'vendors' => ['generic'],
                ],
                'sort_order' => 90,
            ],
        ];
    }
}
