<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pc_shell_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pc_id')->constrained('pcs')->cascadeOnDelete();
            $table->foreignId('shell_game_id')->constrained('shell_games')->cascadeOnDelete();
            $table->boolean('is_installed')->default(false);
            $table->string('version', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['pc_id', 'shell_game_id']);
            $table->index(['tenant_id', 'pc_id', 'is_installed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_shell_games');
    }
};

