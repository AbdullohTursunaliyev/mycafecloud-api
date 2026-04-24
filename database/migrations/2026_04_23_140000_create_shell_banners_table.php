<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shell_banners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 120);
            $table->string('headline', 160)->nullable();
            $table->string('subheadline', 255)->nullable();
            $table->text('body_text')->nullable();
            $table->string('cta_text', 120)->nullable();
            $table->text('prompt_text')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('logo_url', 2048);
            $table->string('audio_url', 2048)->nullable();
            $table->string('accent_color', 16)->nullable();
            $table->string('target_scope', 24)->default('all');
            $table->json('target_zone_ids')->nullable();
            $table->json('target_pc_ids')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('display_seconds')->default(12);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'sort_order'], 'shell_banners_tenant_active_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shell_banners');
    }
};
