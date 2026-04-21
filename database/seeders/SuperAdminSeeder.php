<?php

namespace Database\Seeders;

use App\Models\SuperAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        SuperAdmin::updateOrCreate(
            ['email' => 'admin@mycloudcafe.local'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin12345'),
                'is_active' => true,
            ]
        );
    }
}

