<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPhoneUsernameToClientsTable extends Migration
{
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'phone')) {
                $table->string('phone', 32)->nullable()->index();
            }
            if (!Schema::hasColumn('clients', 'username')) {
                $table->string('username', 64)->nullable()->index();
            }
        });
    }

    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'phone')) $table->dropColumn('phone');
            if (Schema::hasColumn('clients', 'username')) $table->dropColumn('username');
        });
    }
}
