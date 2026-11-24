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
        Schema::create('part_stock_movements', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('part_inventory_id')->unsigned();
            $table->foreign('part_inventory_id')->references('id')->on('part_inventories')->onDelete('cascade');

            $table->enum('type', ['entrada', 'saida', 'ajuste']);
            $table->integer('quantity');
            $table->string('reason')->nullable();
            $table->integer('reference_id')->unsigned()->nullable();

            $table->integer('user_id')->unsigned()->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('part_stock_movements');
    }
};
