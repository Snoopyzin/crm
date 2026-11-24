<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A technician sometimes performs more than one service on the same
 * machine, so "tipo_atendimento" moves from a single value to a JSON array
 * of the selected type names. Existing single-value rows are wrapped into
 * a one-item array so old data keeps displaying correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_services', function (Blueprint $table) {
            $table->text('tipo_atendimento')->nullable()->change();
        });

        DB::table('remote_services')
            ->whereNotNull('tipo_atendimento')
            ->where('tipo_atendimento', '!=', '')
            ->orderBy('id')
            ->get()
            ->each(function ($row) {
                $decoded = json_decode((string) $row->tipo_atendimento, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return;
                }

                DB::table('remote_services')
                    ->where('id', $row->id)
                    ->update(['tipo_atendimento' => json_encode([$row->tipo_atendimento])]);
            });
    }

    public function down(): void
    {
        Schema::table('remote_services', function (Blueprint $table) {
            $table->string('tipo_atendimento')->nullable()->change();
        });
    }
};
