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
        Schema::table('quote_items', function (Blueprint $table) {
            $table->string('tipo')->nullable()->after('name');
            $table->decimal('hours', 8, 2)->nullable()->after('tipo');

            $table->integer('funcionario_id')->unsigned()->nullable()->after('hours');
            $table->foreign('funcionario_id')->references('id')->on('mechanics')->onDelete('set null');

            $table->integer('part_id')->unsigned()->nullable()->after('funcionario_id');
            $table->foreign('part_id')->references('id')->on('parts')->onDelete('set null');

            $table->string('situacao')->nullable()->after('part_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropForeign(['funcionario_id']);
            $table->dropForeign(['part_id']);

            $table->dropColumn(['tipo', 'hours', 'funcionario_id', 'part_id', 'situacao']);
        });
    }
};
