<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateZonesTable extends Migration
{
    public function up()
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            $table->string('name', 120);
            $table->unsignedInteger('price_per_hour')->default(0); // UZS
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // optional foreign key (agar tenants table bor bo'lsa)
            // $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('zones');
    }
}
