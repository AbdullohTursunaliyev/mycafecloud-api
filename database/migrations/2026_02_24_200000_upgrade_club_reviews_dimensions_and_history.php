<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('club_reviews')) {
            return;
        }

        $hasAtmosphere = Schema::hasColumn('club_reviews', 'atmosphere_rating');
        $hasCleanliness = Schema::hasColumn('club_reviews', 'cleanliness_rating');
        $hasTechnical = Schema::hasColumn('club_reviews', 'technical_rating');
        $hasPeripherals = Schema::hasColumn('club_reviews', 'peripherals_rating');

        Schema::table('club_reviews', function (Blueprint $table) use ($hasAtmosphere, $hasCleanliness, $hasTechnical, $hasPeripherals) {
            if (! $hasAtmosphere) {
                $table->unsignedTinyInteger('atmosphere_rating')->nullable()->after('rating');
            }
            if (! $hasCleanliness) {
                $table->unsignedTinyInteger('cleanliness_rating')->nullable()->after('atmosphere_rating');
            }
            if (! $hasTechnical) {
                $table->unsignedTinyInteger('technical_rating')->nullable()->after('cleanliness_rating');
            }
            if (! $hasPeripherals) {
                $table->unsignedTinyInteger('peripherals_rating')->nullable()->after('technical_rating');
            }
        });

        // Allow monthly history: drop one-review-per-client unique constraint.
        try {
            Schema::table('club_reviews', function (Blueprint $table) {
                $table->dropUnique('club_reviews_tenant_id_client_id_unique');
            });
        } catch (\Throwable $e) {
            // ignore if index is already missing or named differently
        }

        Schema::table('club_reviews', function (Blueprint $table) {
            $table->index(['tenant_id', 'client_id', 'created_at'], 'club_reviews_tenant_client_created_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('club_reviews')) {
            return;
        }

        Schema::table('club_reviews', function (Blueprint $table) {
            $table->dropIndex('club_reviews_tenant_client_created_idx');
        });

        Schema::table('club_reviews', function (Blueprint $table) {
            $table->unique(['tenant_id', 'client_id']);
        });

        Schema::table('club_reviews', function (Blueprint $table) {
            if (Schema::hasColumn('club_reviews', 'peripherals_rating')) {
                $table->dropColumn('peripherals_rating');
            }
            if (Schema::hasColumn('club_reviews', 'technical_rating')) {
                $table->dropColumn('technical_rating');
            }
            if (Schema::hasColumn('club_reviews', 'cleanliness_rating')) {
                $table->dropColumn('cleanliness_rating');
            }
            if (Schema::hasColumn('club_reviews', 'atmosphere_rating')) {
                $table->dropColumn('atmosphere_rating');
            }
        });
    }
};
