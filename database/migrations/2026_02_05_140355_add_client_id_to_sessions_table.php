<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('sessions', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('operator_id')
                    ->constrained('clients')->nullOnDelete();
            }
        });
    }

    public function down(): void {
        Schema::table('sessions', function (Blueprint $table) {
            if (Schema::hasColumn('sessions', 'client_id')) {
                $table->dropConstrainedForeignId('client_id');
            }
        });
    }
};

