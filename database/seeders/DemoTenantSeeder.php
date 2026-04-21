<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\LicenseKey;
use App\Models\Operator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::create(['name' => 'Cyber Arena', 'status' => 'active']);

        LicenseKey::create([
            'tenant_id' => $tenant->id,
            'key' => 'A7K9-2Q-DEMO',
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        Operator::create([
            'tenant_id' => $tenant->id,
            'name' => 'Operator 01',
            'login' => 'operator_01',
            'password' => Hash::make('123456'),
            'role' => 'operator',
            'is_active' => true,
        ]);
    }
}

