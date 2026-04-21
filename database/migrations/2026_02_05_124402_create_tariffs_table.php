<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');                 // VIP 1h, Standard
            $table->integer('price_per_hour');      // so'm
            $table->string('zone')->nullable();     // agar zonaga bog'liq bo'lsa
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('tariffs');
    }
};
