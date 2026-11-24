<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('tipo')->nullable()->after('nome');
        });

        // Best-effort backfill: any pre-existing key whose freeform "nome" mentions
        // Focus NFe gets tagged reliably so the fiscal integration can find it.
        DB::table('api_keys')
            ->whereNull('tipo')
            ->where('nome', 'like', '%focus%')
            ->update(['tipo' => 'focusnfe']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
