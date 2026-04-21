<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pc_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pc_id')->constrained('pcs')->cascadeOnDelete();

            $table->string('token_hash')->unique();   // sha256(token)
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'pc_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('pc_device_tokens'); }
};
