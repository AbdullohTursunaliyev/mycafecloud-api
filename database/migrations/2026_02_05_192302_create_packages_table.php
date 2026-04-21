<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');                 // VIP 5 soat
            $table->integer('duration_min');        // masalan 300
            $table->integer('price');               // so'm
            $table->string('zone');                 // VIP, A, B
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tenant_id','zone','is_active']);
        });
    }
    public function down(): void { Schema::dropIfExists('packages'); }
};
