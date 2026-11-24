<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Por qual meio você chegou à nossa empresa?" -- asked on each OS so the
     * Marketing report can measure which channel brings clients/revenue.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('origem_cliente', 30)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('origem_cliente');
        });
    }
};
