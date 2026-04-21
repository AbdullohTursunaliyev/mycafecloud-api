<?php

// database/migrations/xxxx_create_clients_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('account_id')->nullable(); // karta/qr/id
            $table->string('login')->nullable();      // optional
            $table->string('password')->nullable();   // hashed optional

            $table->integer('balance')->default(0);   // so'm
            $table->integer('bonus')->default(0);     // so'm

            $table->string('status')->default('active'); // active|blocked
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id','account_id']);
            $table->unique(['tenant_id','login']);
            $table->index(['tenant_id','status']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('clients');
    }
};

