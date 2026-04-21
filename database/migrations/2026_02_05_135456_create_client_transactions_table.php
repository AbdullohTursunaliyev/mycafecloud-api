<?php

// database/migrations/xxxx_create_client_transactions_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('client_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();

            $table->string('type'); // topup|charge|refund|bonus
            $table->integer('amount'); // +/-
            $table->integer('bonus_amount')->default(0);
            $table->string('payment_method')->nullable(); // cash|card|balance
            $table->string('comment')->nullable();

            $table->timestamps();

            $table->index(['tenant_id','client_id','type']);
            $table->index(['tenant_id','created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('client_transactions');
    }
};

