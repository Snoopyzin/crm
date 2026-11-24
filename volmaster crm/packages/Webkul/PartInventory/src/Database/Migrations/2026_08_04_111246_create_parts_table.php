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
        Schema::create('parts', function (Blueprint $table) {
            $table->increments('id');

            $table->string('codigo')->unique();
            $table->string('nome');

            $table->integer('part_category_id')->unsigned()->nullable();
            $table->foreign('part_category_id')->references('id')->on('part_categories')->onDelete('set null');

            $table->decimal('valor_unitario', 12, 4);
            $table->string('fornecedor')->nullable();
            $table->unsignedInteger('quantidade_minima')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parts');
    }
};
