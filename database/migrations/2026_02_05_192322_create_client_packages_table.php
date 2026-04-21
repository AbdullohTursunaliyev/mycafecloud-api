<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('client_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();

            $table->integer('remaining_min');       // qolgan minut
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('active'); // active|used|expired

            $table->timestamps();

            $table->index(['tenant_id','client_id','status']);
        });
    }
    public function down(): void { Schema::dropIfExists('client_packages'); }
};

