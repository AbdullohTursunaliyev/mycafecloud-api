<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('pc_id')->constrained('pcs')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            $table->foreignId('created_by_operator_id')->constrained('operators')->cascadeOnDelete();

            $table->timestamp('start_at');
            $table->timestamp('end_at');

            $table->string('status')->default('active'); // active|canceled|expired|completed
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['tenant_id','pc_id','status']);
            $table->index(['tenant_id','client_id','status']);
            $table->index(['tenant_id','start_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('bookings'); }
};

