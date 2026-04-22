<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS shifts_one_open_per_tenant_idx
            ON shifts (tenant_id)
            WHERE closed_at IS NULL
        ");

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS client_subscriptions_one_active_per_zone_idx
            ON client_subscriptions (tenant_id, client_id, zone_id)
            WHERE status = 'active'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shifts_one_open_per_tenant_idx');
        DB::statement('DROP INDEX IF EXISTS client_subscriptions_one_active_per_zone_idx');
    }
};
