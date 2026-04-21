<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('subscription_plan_id')->index();
            $table->unsignedBigInteger('zone_id')->index();

            $table->string('status')->default('active'); // active|expired|canceled
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->string('payment_method')->nullable(); // balance|cash|card
            $table->unsignedBigInteger('shift_id')->nullable()->index();
            $table->unsignedBigInteger('operator_id')->nullable()->index();

            $table->unsignedBigInteger('amount')->default(0);
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['tenant_id','client_id','zone_id','status']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_subscriptions');
    }
};
