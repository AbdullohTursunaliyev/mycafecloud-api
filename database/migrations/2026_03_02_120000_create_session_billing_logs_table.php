<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_billing_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('pc_id')->nullable();
            $table->string('mode', 20)->default('wallet'); // wallet|package
            $table->unsignedInteger('minutes')->default(0);
            $table->unsignedInteger('amount')->default(0);
            $table->unsignedInteger('price_per_hour')->nullable();
            $table->unsignedInteger('price_per_min')->nullable();
            $table->unsignedInteger('balance_before')->nullable();
            $table->unsignedInteger('bonus_before')->nullable();
            $table->unsignedInteger('balance_after')->nullable();
            $table->unsignedInteger('bonus_after')->nullable();
            $table->unsignedInteger('remaining_min_before')->nullable();
            $table->unsignedInteger('remaining_min_after')->nullable();
            $table->string('reason', 120)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'session_id']);
            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'pc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_billing_logs');
    }
};
