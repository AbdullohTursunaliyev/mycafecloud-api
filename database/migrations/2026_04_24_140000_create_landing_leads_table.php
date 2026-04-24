<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80)->default('nexora-landing')->index();
            $table->string('club_name', 160);
            $table->string('city', 120)->nullable();
            $table->unsignedInteger('pc_count')->default(0);
            $table->string('plan_code', 40)->nullable()->index();
            $table->string('contact', 160);
            $table->text('message')->nullable();
            $table->string('locale', 10)->nullable();
            $table->string('status', 30)->default('new')->index();
            $table->json('meta')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['plan_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_leads');
    }
};
