<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('club_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_reviews');
    }
};

