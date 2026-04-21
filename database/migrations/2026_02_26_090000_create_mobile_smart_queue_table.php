<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_smart_queue', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('zone_key', 96)->nullable()->index();
            $table->boolean('notify_on_free')->default(true);
            $table->string('status', 20)->default('waiting')->index();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'client_id', 'status']);
            $table->index(['tenant_id', 'zone_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_smart_queue');
    }
};

