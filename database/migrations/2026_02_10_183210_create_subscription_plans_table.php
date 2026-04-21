<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('zone_id')->index();
            $table->string('name');
            $table->unsignedInteger('duration_days'); // 30, 7, 90...
            $table->unsignedBigInteger('price'); // UZS
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id','zone_id','is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
