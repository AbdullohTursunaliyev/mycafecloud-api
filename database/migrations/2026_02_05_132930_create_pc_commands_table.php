<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pc_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pc_id')->constrained('pcs')->cascadeOnDelete();

            $table->string('type');                 // LOCK, UNLOCK, REBOOT, SHUTDOWN, MESSAGE
            $table->jsonb('payload')->nullable();   // {"text":"..."}
            $table->string('status')->default('pending'); // pending|sent|done|failed
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('ack_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id','pc_id','status']);
        });
    }
    public function down(): void { Schema::dropIfExists('pc_commands'); }
};

