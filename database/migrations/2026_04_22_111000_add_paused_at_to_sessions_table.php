<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('sessions', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('last_billed_at');
                $table->index(['tenant_id', 'paused_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            if (Schema::hasColumn('sessions', 'paused_at')) {
                $table->dropIndex(['tenant_id', 'paused_at']);
                $table->dropColumn('paused_at');
            }
        });
    }
};
