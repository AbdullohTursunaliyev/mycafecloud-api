<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // pgsql: varchar
            if (!Schema::hasColumn('clients', 'phone')) {
                $table->string('phone', 32)->nullable()->after('login');
                // agar clients jadvalida "login" bo'lmasa, after() ni olib tashlang:
                // $table->string('phone', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'phone')) {
                $table->dropColumn('phone');
            }
        });
    }
};
