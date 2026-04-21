<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pc_cells', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            $table->unsignedInteger('row');
            $table->unsignedInteger('col');

            $table->unsignedBigInteger('zone_id')->nullable()->index();
            $table->unsignedBigInteger('pc_id')->nullable()->index();

            $table->string('label', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'row', 'col']);
            $table->unique(['tenant_id', 'pc_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('pc_cells');
    }
};
