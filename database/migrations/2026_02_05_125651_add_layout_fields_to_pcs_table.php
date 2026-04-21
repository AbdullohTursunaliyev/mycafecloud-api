<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('pcs', function (Blueprint $table) {
            $table->integer('pos_x')->nullable();      // map x
            $table->integer('pos_y')->nullable();      // map y
            $table->string('group')->nullable();       // optional: "Row-1", "Left Wing"
            $table->integer('sort_order')->default(0); // custom ordering
            $table->string('notes')->nullable();
            $table->boolean('is_hidden')->default(false);

            $table->index(['tenant_id', 'is_hidden']);
        });
    }

    public function down(): void {
        Schema::table('pcs', function (Blueprint $table) {
            $table->dropColumn(['pos_x','pos_y','group','sort_order','notes','is_hidden']);
        });
    }
};

