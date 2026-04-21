<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('login');
            $table->string('password'); // hashed
            $table->string('role')->default('operator'); // operator/admin
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'login']);
            $table->index(['tenant_id', 'is_active']);
        });
    }
    public function down(): void { Schema::dropIfExists('operators'); }
};
