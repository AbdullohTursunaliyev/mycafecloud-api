<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_visuals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 120);
            $table->string('headline', 160)->nullable();
            $table->string('subheadline', 255)->nullable();
            $table->text('description_text')->nullable();
            $table->text('prompt_text')->nullable();
            $table->string('display_mode', 24)->default('upload');
            $table->string('screen_mode', 24)->default('poster');
            $table->string('accent_color', 16)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('audio_url', 2048)->nullable();
            $table->json('layout_spec')->nullable();
            $table->json('visual_spec')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'sort_order'], 'club_visuals_tenant_active_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_visuals');
    }
};
