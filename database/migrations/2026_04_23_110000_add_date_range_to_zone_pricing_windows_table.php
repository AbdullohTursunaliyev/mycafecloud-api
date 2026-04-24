<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zone_pricing_windows', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->after('ends_at');
            $table->date('ends_on')->nullable()->after('starts_on');

            $table->index(['tenant_id', 'zone_id', 'starts_on', 'ends_on'], 'zone_pricing_windows_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('zone_pricing_windows', function (Blueprint $table) {
            $table->dropIndex('zone_pricing_windows_date_idx');
            $table->dropColumn(['starts_on', 'ends_on']);
        });
    }
};
