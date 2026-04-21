<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('pc_commands', 'batch_id')) {
            Schema::table('pc_commands', function (Blueprint $table) {
                $table->string('batch_id', 64)->nullable()->after('pc_id');
                $table->index(['tenant_id', 'batch_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pc_commands', 'batch_id')) {
            Schema::table('pc_commands', function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'batch_id']);
                $table->dropColumn('batch_id');
            });
        }
    }
};

