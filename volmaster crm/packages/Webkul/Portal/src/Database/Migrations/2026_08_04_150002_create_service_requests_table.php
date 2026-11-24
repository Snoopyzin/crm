<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('person_id')->unsigned();
            $table->foreign('person_id')->references('id')->on('persons')->onDelete('cascade');

            $table->integer('client_user_id')->unsigned();
            $table->foreign('client_user_id')->references('id')->on('client_users')->onDelete('cascade');

            $table->integer('vehicle_id')->unsigned()->nullable();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('set null');
            $table->string('veiculo_descricao_livre')->nullable();

            $table->text('descricao');
            $table->date('data_preferencia')->nullable();
            $table->string('status')->default('Pendente');

            $table->integer('service_order_id')->unsigned()->nullable();
            $table->foreign('service_order_id')->references('id')->on('quotes')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
