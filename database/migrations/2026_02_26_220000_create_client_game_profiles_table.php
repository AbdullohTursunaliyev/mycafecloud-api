<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_game_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('game_slug', 64);
            $table->json('profile_json')->nullable();
            $table->json('mouse_json')->nullable();
            $table->string('archive_path', 512)->nullable();
            $table->unsignedBigInteger('archive_size')->nullable();
            $table->string('archive_sha1', 40)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('last_pc_id')->nullable()->constrained('pcs')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'client_id', 'game_slug'], 'client_game_profiles_unique');
            $table->index(['tenant_id', 'client_id', 'updated_at'], 'client_game_profiles_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_game_profiles');
    }
};

