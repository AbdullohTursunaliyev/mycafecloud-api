<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('lifetime_topup')->default(0)->after('bonus');
            $table->unsignedBigInteger('tier_id')->nullable()->index()->after('lifetime_topup');
            $table->timestamp('tier_changed_at')->nullable()->after('tier_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['lifetime_topup','tier_id','tier_changed_at']);
        });
    }
};

