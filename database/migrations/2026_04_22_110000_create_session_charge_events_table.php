<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_charge_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('sessions')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('pc_id')->nullable()->constrained('pcs')->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->string('source_type', 30)->default('wallet');
            $table->string('rule_type', 40)->nullable();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->timestamp('period_started_at');
            $table->timestamp('period_ended_at');
            $table->unsignedInteger('billable_units')->default(0);
            $table->string('unit_kind', 20)->default('minute');
            $table->unsignedInteger('unit_price')->default(0);
            $table->unsignedInteger('amount')->default(0);
            $table->unsignedInteger('wallet_before')->nullable();
            $table->unsignedInteger('wallet_after')->nullable();
            $table->unsignedInteger('package_before_min')->nullable();
            $table->unsignedInteger('package_after_min')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'session_id']);
            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'pc_id']);
            $table->index(['tenant_id', 'zone_id']);
            $table->index(['tenant_id', 'rule_type', 'rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_charge_events');
    }
};
