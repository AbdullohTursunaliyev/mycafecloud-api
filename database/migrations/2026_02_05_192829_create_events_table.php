<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('type');                // session_started, balance_topped_up ...
            $table->string('source');              // system|operator|agent|client
            $table->string('entity_type');         // session|client|pc
            $table->unsignedBigInteger('entity_id');

            $table->jsonb('payload')->nullable();  // full data snapshot
            $table->timestamps();

            $table->index(['tenant_id','type']);
            $table->index(['tenant_id','created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('events'); }
};

