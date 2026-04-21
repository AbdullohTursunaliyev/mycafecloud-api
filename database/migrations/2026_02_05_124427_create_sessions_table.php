<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('pc_id')->constrained('pcs')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();

            $table->unsignedBigInteger('user_id')->nullable(); // keyin users jadvali qo'shilganda FK qilamiz
            $table->foreignId('tariff_id')->nullable()->constrained('tariffs')->nullOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->integer('price_total')->default(0); // so'm
            $table->string('status')->default('active'); // active|finished|canceled

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'pc_id', 'status']);
            $table->index(['tenant_id', 'started_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('sessions');
    }
};

