<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('person_id')->unsigned();
            $table->foreign('person_id')->references('id')->on('persons')->onDelete('cascade');

            $table->string('marca');
            $table->string('modelo');
            $table->string('placa')->unique();
            $table->year('ano')->nullable();
            $table->string('chassi')->nullable();
            $table->unsignedInteger('km')->default(0);
            $table->string('cor')->nullable();
            $table->enum('tipo', ['carros', 'motos', 'caminhoes'])->default('caminhoes');
            $table->enum('combustivel', ['Flex', 'Gasolina', 'Etanol', 'Diesel', 'Elétrico', 'Híbrido', 'GNV'])->nullable();
            $table->string('status')->default('Ativo');
            $table->string('photo_path')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
