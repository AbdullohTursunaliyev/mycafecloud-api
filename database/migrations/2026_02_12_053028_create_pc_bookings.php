<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pc_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('pc_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->timestamp('reserved_until');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('pc_id')->references('id')->on('pcs')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();

            $table->unique(['tenant_id','pc_id']); // 1 pc = 1 booking
        });
    }
    public function down(): void {
        Schema::dropIfExists('pc_bookings');
    }
};

