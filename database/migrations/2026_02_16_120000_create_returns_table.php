<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('operator_id')->index();
            $table->unsignedBigInteger('shift_id')->nullable()->index();

            $table->string('type', 20); // topup | package
            $table->integer('amount');
            $table->string('payment_method', 20)->nullable(); // cash | card | balance

            $table->string('source_type', 40); // client_transaction | package_sale
            $table->unsignedBigInteger('source_id');
            $table->jsonb('meta')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};

