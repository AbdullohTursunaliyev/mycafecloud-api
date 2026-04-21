<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddZoneIdToPcsTable extends Migration
{
    public function up()
    {
        Schema::table('pcs', function (Blueprint $table) {
            if (!Schema::hasColumn('pcs', 'zone_id')) {
                $table->unsignedBigInteger('zone_id')->nullable()->index()->after('id');
            }

            // optional fk
            // $table->foreign('zone_id')->references('id')->on('zones')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('pcs', function (Blueprint $table) {
            // optional: drop fk first
            // $table->dropForeign(['zone_id']);
            if (Schema::hasColumn('pcs', 'zone_id')) {
                $table->dropColumn('zone_id');
            }
        });
    }
}
