<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saas_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->default('active');
            $table->unsignedInteger('price_per_pc_uzs')->default(0);
            $table->json('features')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('saas_plans')->insert([
            [
                'code' => 'basic',
                'name' => 'Basic',
                'status' => 'active',
                'price_per_pc_uzs' => 0,
                'features' => json_encode([
                    'nexora_ai' => false,
                    'ai_generation' => false,
                    'ai_insights' => false,
                    'ai_autopilot' => false,
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'status' => 'active',
                'price_per_pc_uzs' => 0,
                'features' => json_encode([
                    'nexora_ai' => true,
                    'ai_generation' => true,
                    'ai_insights' => true,
                    'ai_autopilot' => true,
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('saas_plan_id')
                ->nullable()
                ->after('status')
                ->constrained('saas_plans')
                ->nullOnDelete();
        });

        $basicPlanId = DB::table('saas_plans')->where('code', 'basic')->value('id');
        if ($basicPlanId) {
            DB::table('tenants')
                ->whereNull('saas_plan_id')
                ->update(['saas_plan_id' => $basicPlanId]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saas_plan_id');
        });

        Schema::dropIfExists('saas_plans');
    }
};
