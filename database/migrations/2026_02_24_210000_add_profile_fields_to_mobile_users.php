<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mobile_users', function (Blueprint $table) {
            if (!Schema::hasColumn('mobile_users', 'first_name')) {
                $table->string('first_name', 64)->nullable()->after('password_hash');
            }
            if (!Schema::hasColumn('mobile_users', 'last_name')) {
                $table->string('last_name', 64)->nullable()->after('first_name');
            }
            if (!Schema::hasColumn('mobile_users', 'avatar_url')) {
                $table->text('avatar_url')->nullable()->after('last_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mobile_users', function (Blueprint $table) {
            if (Schema::hasColumn('mobile_users', 'avatar_url')) {
                $table->dropColumn('avatar_url');
            }
            if (Schema::hasColumn('mobile_users', 'last_name')) {
                $table->dropColumn('last_name');
            }
            if (Schema::hasColumn('mobile_users', 'first_name')) {
                $table->dropColumn('first_name');
            }
        });
    }
};
