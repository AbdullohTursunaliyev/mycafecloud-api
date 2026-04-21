<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pc_pair_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();           // 6-10 chars
            $table->string('zone')->nullable();         // optional default zone
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('pc_id')->nullable()->constrained('pcs')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'expires_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('pc_pair_codes'); }
};

