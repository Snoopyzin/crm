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
        Schema::table('quotes', function (Blueprint $table) {
            $table->integer('vehicle_id')->unsigned()->nullable()->after('person_id');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('set null');

            $table->integer('mechanic_id')->unsigned()->nullable()->after('vehicle_id');
            $table->foreign('mechanic_id')->references('id')->on('mechanics')->onDelete('set null');

            $table->string('workshop_status')->nullable()->after('mechanic_id');
            $table->date('data_previsao')->nullable()->after('workshop_status');
            $table->dateTime('data_conclusao')->nullable()->after('data_previsao');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropForeign(['mechanic_id']);

            $table->dropColumn(['vehicle_id', 'mechanic_id', 'workshop_status', 'data_previsao', 'data_conclusao']);
        });
    }
};
