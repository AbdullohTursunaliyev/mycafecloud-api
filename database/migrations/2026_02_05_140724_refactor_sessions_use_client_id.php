<?php

// database/migrations/xxxx_refactor_sessions_use_client_id.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sessions', function (Blueprint $table) {
            // user_id bo'lsa olib tashlaymiz
            if (Schema::hasColumn('sessions', 'user_id')) {
                $table->dropColumn('user_id');
            }

            // client_id bo'lmasa qo'shamiz (xavfsizlik)
            if (!Schema::hasColumn('sessions', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('operator_id')
                    ->constrained('clients')->nullOnDelete();
            }

            // MVP: client_id majburiy bo'lsin (agar hozir bo'sh data yo'q deb hisoblaymiz)
            // Agar production data bo'lsa, avval backfill kerak bo'ladi.
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });
    }

    public function down(): void {
        Schema::table('sessions', function (Blueprint $table) {
            // rollback qilish shart bo'lmasa ham, minimal:
            // $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });
    }
};

