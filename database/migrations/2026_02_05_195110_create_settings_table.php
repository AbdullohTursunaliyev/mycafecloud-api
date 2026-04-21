<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('key');              // billing.interval, pc.heartbeat_timeout
            $table->jsonb('value');             // har xil type bo‘lishi mumkin
            $table->timestamps();

            $table->unique(['tenant_id','key']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('settings');
    }
};

