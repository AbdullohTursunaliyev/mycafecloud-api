<?php

namespace Database\Seeders;

use App\Models\ClientTier;
use Illuminate\Database\Seeder;

class ClientTierSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = 1;

        $tiers = [
            ['name'=>'Новобранец','slug'=>'recruit','min_total'=>0,         'bonus_on_upgrade'=>0,      'color'=>'#9CA3AF','icon'=>'🛡️','priority'=>100],
            ['name'=>'Игрок','slug'=>'player','min_total'=>1_000_000,      'bonus_on_upgrade'=>20_000,  'color'=>'#22C55E','icon'=>'🎮','priority'=>90],
            ['name'=>'Рыцарь','slug'=>'knight','min_total'=>5_000_000,     'bonus_on_upgrade'=>50_000,  'color'=>'#3B82F6','icon'=>'⚔️','priority'=>80],
            ['name'=>'Ветеран','slug'=>'veteran','min_total'=>15_000_000,  'bonus_on_upgrade'=>120_000, 'color'=>'#8B5CF6','icon'=>'🧠','priority'=>70],
            ['name'=>'Элита','slug'=>'elite','min_total'=>30_000_000,      'bonus_on_upgrade'=>250_000, 'color'=>'#F59E0B','icon'=>'👑','priority'=>60],
            ['name'=>'Инвестор','slug'=>'investor','min_total'=>60_000_000, 'bonus_on_upgrade'=>500_000, 'color'=>'#EF4444','icon'=>'💎','priority'=>50],
            ['name'=>'Легенда','slug'=>'legend','min_total'=>100_000_000,  'bonus_on_upgrade'=>1_000_000,'color'=>'gradient','icon'=>'🐉','priority'=>40],
        ];

        foreach ($tiers as $t) {
            ClientTier::updateOrCreate(
                ['tenant_id'=>$tenantId, 'slug'=>$t['slug']],
                $t
            );
        }
    }
}

