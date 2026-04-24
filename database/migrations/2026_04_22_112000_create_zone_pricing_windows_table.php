<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zone_pricing_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained('zones')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->json('weekdays')->nullable();
            $table->unsignedInteger('price_per_hour');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'zone_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_pricing_windows');
    }
};
