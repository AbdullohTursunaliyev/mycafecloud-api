<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobile_friendships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mobile_user_id');
            $table->unsignedBigInteger('friend_mobile_user_id');
            $table->unsignedBigInteger('requested_by_mobile_user_id');
            $table->string('status', 24)->default('pending'); // pending|accepted|blocked
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['mobile_user_id', 'friend_mobile_user_id'], 'mobile_friendships_pair_unique');
            $table->index(['status', 'updated_at']);
            $table->index(['requested_by_mobile_user_id']);

            $table->foreign('mobile_user_id')->references('id')->on('mobile_users')->onDelete('cascade');
            $table->foreign('friend_mobile_user_id')->references('id')->on('mobile_users')->onDelete('cascade');
            $table->foreign('requested_by_mobile_user_id')->references('id')->on('mobile_users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_friendships');
    }
};

