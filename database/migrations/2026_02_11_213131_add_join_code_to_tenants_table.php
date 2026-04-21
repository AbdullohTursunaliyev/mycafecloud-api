<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('join_code', 32)->nullable()->unique();
            $table->boolean('join_code_active')->default(true);
            $table->timestamp('join_code_expires_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['join_code']);
            $table->dropColumn(['join_code','join_code_active','join_code_expires_at']);
        });
    }

};
