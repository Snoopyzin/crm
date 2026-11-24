<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which team (Diagnóstico/Programações) this occurrence belongs to, derived
 * from its selected tipo_atendimento -- this is what actually controls who
 * can see it (not who created/is assigned to it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_services', function (Blueprint $table) {
            $table->integer('grupo_id')->unsigned()->nullable()->after('tipo_atendimento');
            $table->foreign('grupo_id')->references('id')->on('groups')->onDelete('set null');
        });

        // Backfill existing rows so they don't silently disappear for
        // group-restricted users once this ships.
        DB::table('remote_services')
            ->whereNotNull('tipo_atendimento')
            ->get(['id', 'tipo_atendimento'])
            ->each(function ($row) {
                $tipos = json_decode((string) $row->tipo_atendimento, true);

                if (empty($tipos) || ! is_array($tipos)) {
                    return;
                }

                $grupoId = DB::table('remote_service_types')
                    ->whereIn('nome', $tipos)
                    ->whereNotNull('group_id')
                    ->value('group_id');

                if ($grupoId) {
                    DB::table('remote_services')->where('id', $row->id)->update(['grupo_id' => $grupoId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('remote_services', function (Blueprint $table) {
            $table->dropForeign(['grupo_id']);
            $table->dropColumn('grupo_id');
        });
    }
};
