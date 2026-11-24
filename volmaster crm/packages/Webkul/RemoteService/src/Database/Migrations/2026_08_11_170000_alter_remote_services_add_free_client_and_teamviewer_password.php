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
        Schema::table('remote_services', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
        });

        Schema::table('remote_services', function (Blueprint $table) {
            $table->integer('person_id')->unsigned()->nullable()->change();
            $table->foreign('person_id')->references('id')->on('persons')->onDelete('set null');

            $table->string('cliente_nome_livre')->nullable()->after('person_id');
            $table->string('teamviewer_password')->nullable()->after('teamviewer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('remote_services', function (Blueprint $table) {
            $table->dropColumn(['cliente_nome_livre', 'teamviewer_password']);
        });
    }
};
