<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS sessions_one_active_per_pc_idx
            ON sessions (tenant_id, pc_id)
            WHERE status = 'active'
        ");

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS bookings_one_active_per_pc_idx
            ON bookings (tenant_id, pc_id)
            WHERE status = 'active'
        ");

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS bookings_one_active_per_client_idx
            ON bookings (tenant_id, client_id)
            WHERE status = 'active'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sessions_one_active_per_pc_idx');
        DB::statement('DROP INDEX IF EXISTS bookings_one_active_per_pc_idx');
        DB::statement('DROP INDEX IF EXISTS bookings_one_active_per_client_idx');
    }
};
