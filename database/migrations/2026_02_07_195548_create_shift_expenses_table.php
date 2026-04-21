<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_shift_expenses_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('operator_id');

            $table->integer('amount'); // UZS
            $table->string('category', 64)->nullable(); // masalan: food, repair, salary, other
            $table->string('title', 120); // qisqa nom
            $table->string('note', 255)->nullable();

            $table->timestamp('spent_at')->useCurrent();

            $table->timestamps();

            $table->index(['tenant_id', 'shift_id']);
            $table->index(['tenant_id', 'spent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_expenses');
    }
};

