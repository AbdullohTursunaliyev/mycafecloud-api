<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('code');              // A28, VIP12
            $table->string('zone')->nullable();  // VIP, A, B...
            $table->string('status')->default('offline'); // offline|online|busy|reserved|maintenance|locked
            $table->string('ip_address')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'zone']);
            $table->index(['tenant_id', 'last_seen_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('pcs'); }
};
