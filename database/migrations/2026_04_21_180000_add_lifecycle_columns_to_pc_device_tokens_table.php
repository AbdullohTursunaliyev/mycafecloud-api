<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pc_device_tokens', function (Blueprint $table) {
            $table->foreignId('rotated_from_id')
                ->nullable()
                ->after('pc_id')
                ->constrained('pc_device_tokens')
                ->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->after('token_hash');
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
            $table->string('revocation_reason', 120)->nullable()->after('revoked_at');
            $table->index(['tenant_id', 'pc_id', 'revoked_at'], 'pc_device_tokens_tenant_pc_revoked_idx');
            $table->index('expires_at', 'pc_device_tokens_expires_at_idx');
        });

        DB::table('pc_device_tokens')
            ->whereNull('expires_at')
            ->update([
                'expires_at' => Carbon::now()->addHours((int) config('domain.agent.device_token_ttl_hours', 24 * 30)),
            ]);
    }

    public function down(): void
    {
        Schema::table('pc_device_tokens', function (Blueprint $table) {
            $table->dropIndex('pc_device_tokens_tenant_pc_revoked_idx');
            $table->dropIndex('pc_device_tokens_expires_at_idx');
            $table->dropConstrainedForeignId('rotated_from_id');
            $table->dropColumn(['expires_at', 'revoked_at', 'revocation_reason']);
        });
    }
};
