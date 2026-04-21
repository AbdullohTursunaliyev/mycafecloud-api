<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shell_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64);
            $table->string('title', 120);
            $table->string('category', 40)->nullable();
            $table->string('age_rating', 10)->nullable();
            $table->string('badge', 60)->nullable();
            $table->string('note', 200)->nullable();
            $table->string('launcher', 40)->nullable();
            $table->string('launcher_icon', 8)->nullable();
            $table->text('image_url')->nullable();
            $table->text('hero_url')->nullable();
            $table->text('trailer_url')->nullable();
            $table->text('website_url')->nullable();
            $table->text('help_text')->nullable();
            $table->text('launch_command')->nullable();
            $table->text('launch_args')->nullable();
            $table->text('working_dir')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(1000);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shell_games');
    }
};

