<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pc_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pc_id')->constrained('pcs')->cascadeOnDelete();
            $table->timestamp('received_at');
            $table->jsonb('metrics')->nullable(); // cpu/ram/app/user
            $table->timestamps();

            $table->index(['tenant_id','pc_id','received_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('pc_heartbeats');
    }
};

