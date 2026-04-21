<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shell_games', function (Blueprint $table) {
            if (!Schema::hasColumn('shell_games', 'cloud_profile_enabled')) {
                $table->boolean('cloud_profile_enabled')->default(true)->after('working_dir');
            }
            if (!Schema::hasColumn('shell_games', 'config_paths')) {
                $table->json('config_paths')->nullable()->after('cloud_profile_enabled');
            }
            if (!Schema::hasColumn('shell_games', 'mouse_hints')) {
                $table->json('mouse_hints')->nullable()->after('config_paths');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shell_games', function (Blueprint $table) {
            if (Schema::hasColumn('shell_games', 'mouse_hints')) {
                $table->dropColumn('mouse_hints');
            }
            if (Schema::hasColumn('shell_games', 'config_paths')) {
                $table->dropColumn('config_paths');
            }
            if (Schema::hasColumn('shell_games', 'cloud_profile_enabled')) {
                $table->dropColumn('cloud_profile_enabled');
            }
        });
    }
};

