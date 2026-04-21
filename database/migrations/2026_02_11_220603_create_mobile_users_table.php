<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobile_users', function (Blueprint $table) {
            $table->id();
            $table->string('login')->unique();
            $table->string('password_hash'); // bcrypt
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_users');
    }
};
