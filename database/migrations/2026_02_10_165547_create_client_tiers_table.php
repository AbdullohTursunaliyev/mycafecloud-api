<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            $table->string('name');            // Рыцарь
            $table->string('slug');            // knight
            $table->unsignedBigInteger('min_total'); // min lifetime topup (UZS)
            $table->unsignedBigInteger('bonus_on_upgrade')->default(0); // bonus when reach this tier
            $table->string('color')->nullable(); // #3B82F6 or "gradient:...."
            $table->string('icon')->nullable();  // ⚔️
            $table->unsignedInteger('priority')->default(100); // small => higher tier

            $table->timestamps();

            $table->unique(['tenant_id','slug']);
            $table->index(['tenant_id','min_total']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_tiers');
    }
};

