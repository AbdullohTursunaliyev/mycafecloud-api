<?php

// database/migrations/xxxx_create_shifts_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('opened_by_operator_id')->constrained('operators')->cascadeOnDelete();
            $table->foreignId('closed_by_operator_id')->nullable()->constrained('operators')->nullOnDelete();

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            $table->integer('opening_cash')->default(0); // kassadagi boshlang'ich pul
            $table->integer('closing_cash')->nullable(); // operator kiritadi

            $table->integer('topups_cash_total')->default(0);
            $table->integer('topups_card_total')->default(0);

            $table->integer('packages_cash_total')->default(0);
            $table->integer('packages_card_total')->default(0);

            $table->integer('adjustments_total')->default(0); // qo'lda +/-
            $table->string('status')->default('open'); // open|closed

            $table->jsonb('meta')->nullable(); // note, details, etc
            $table->timestamps();

            $table->index(['tenant_id','status']);
            $table->index(['tenant_id','opened_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('shifts'); }
};
