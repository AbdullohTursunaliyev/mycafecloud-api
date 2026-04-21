<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('operator_id')->nullable()->change();
            $table->unsignedBigInteger('tariff_id')->nullable()->change(); // sizda tarif yo‘q bo‘lsa ham foydali
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('operator_id')->nullable(false)->change();
            // tariff_id ni eski holatga qaytarish shart bo‘lmasa ham bo‘ladi
        });
    }
};

