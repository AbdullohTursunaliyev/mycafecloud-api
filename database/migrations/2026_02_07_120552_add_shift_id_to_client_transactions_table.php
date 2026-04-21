<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable()->after('operator_id');
            $table->index(['tenant_id', 'shift_id']);
            // agar shiftlar o‘chirilsa tx qolaversin desang:
            // $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_transactions', function (Blueprint $table) {
            // $table->dropForeign(['shift_id']); // foreign qo‘ygan bo‘lsang
            $table->dropIndex(['tenant_id', 'shift_id']);
            $table->dropColumn('shift_id');
        });
    }
};

