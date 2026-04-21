<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sessions', function (Blueprint $table) {
            $table->foreignId('client_package_id')->nullable()
                ->constrained('client_packages')->nullOnDelete();
            $table->boolean('is_package')->default(false);
        });
    }
    public function down(): void {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_package_id');
            $table->dropColumn('is_package');
        });
    }
};

