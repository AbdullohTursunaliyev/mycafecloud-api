<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (!Schema::hasColumn('shifts', 'diff_overage')) {
                $table->integer('diff_overage')->default(0);
            }
            if (!Schema::hasColumn('shifts', 'diff_shortage')) {
                $table->integer('diff_shortage')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'diff_overage')) {
                $table->dropColumn('diff_overage');
            }
            if (Schema::hasColumn('shifts', 'diff_shortage')) {
                $table->dropColumn('diff_shortage');
            }
        });
    }
};
