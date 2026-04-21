<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            // Billing tick uchun: sessiya qachongacha hisoblab bo'linganini saqlaymiz.
            // Null bo'lsa, started_at dan hisoblaymiz.
            $table->timestamp('last_billed_at')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('last_billed_at');
        });
    }
};
