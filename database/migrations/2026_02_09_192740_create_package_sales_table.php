<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePackageSalesTable extends Migration
{
    public function up()
    {
        Schema::create('package_sales', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('package_id')->index();

            // cash | card | balance
            $table->string('payment_method', 20)->index();

            // shiftga faqat cash/card bo‘lsa bog‘laymiz
            $table->unsignedBigInteger('shift_id')->nullable()->index();

            // kim bajargan (operator/admin/owner)
            $table->unsignedBigInteger('operator_id')->nullable()->index();

            $table->integer('amount'); // paket narxi (UZS)
            $table->jsonb('meta')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('package_sales');
    }
}
