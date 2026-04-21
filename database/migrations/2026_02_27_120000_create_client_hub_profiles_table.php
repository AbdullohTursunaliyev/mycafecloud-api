<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_hub_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->json('recent_json')->nullable();
            $table->json('favorites_json')->nullable();
            $table->string('last_pc_code', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'client_id'], 'client_hub_profiles_unique');
            $table->index(['tenant_id', 'updated_at'], 'client_hub_profiles_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_hub_profiles');
    }
};

