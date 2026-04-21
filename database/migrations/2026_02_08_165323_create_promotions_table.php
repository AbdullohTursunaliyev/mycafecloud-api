<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id')->index();

            $table->string('name', 120);
            // hozircha bitta tip: double_topup (2x)
            $table->string('type', 50)->default('double_topup');

            $table->boolean('is_active')->default(true)->index();

            // 0=Sunday ... 5=Friday ... 6=Saturday (Carbon dayOfWeek)
            $table->json('days_of_week')->nullable();

            // optional time window
            $table->time('time_from')->nullable();
            $table->time('time_to')->nullable();

            // hozircha cash, keyin any/card qilamiz
            $table->string('applies_payment_method', 20)->default('cash');

            // optional campaign bounds
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->integer('priority')->default(10);

            $table->timestamps();

            // agar sizda tenants jadvali bor bo'lsa, FK qo'shishingiz mumkin
            // $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
