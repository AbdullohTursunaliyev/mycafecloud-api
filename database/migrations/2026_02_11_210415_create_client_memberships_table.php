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
        Schema::create('client_memberships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('identity_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('client_id');
            $table->timestamps();

            $table->unique(['identity_id','tenant_id']);

            $table->foreign('identity_id')->references('id')->on('client_identities')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_memberships');
    }
};
