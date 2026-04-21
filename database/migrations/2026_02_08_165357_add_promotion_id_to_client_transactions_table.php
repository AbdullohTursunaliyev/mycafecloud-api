<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('client_transactions', 'promotion_id')) {
                $table->unsignedBigInteger('promotion_id')->nullable()->index()->after('shift_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('client_transactions', 'promotion_id')) {
                $table->dropColumn('promotion_id');
            }
        });
    }
};
