<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the "Diagnóstico" / "Programações" groups used to separate which
 * technicians see which Atendimentos Remotos (via each user's "Permissão de
 * Visualização" = Grupo, in Configurações > Usuários > Usuários).
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = [
            [
                'name' => 'Diagnóstico',
                'description' => 'Técnicos que atendem ocorrências de diagnóstico (ex: Atualização de APCI).',
            ],
            [
                'name' => 'Programações',
                'description' => 'Técnicos que atendem programações (ex: Programar APM, Programar ACM).',
            ],
        ];

        foreach ($groups as $group) {
            if (DB::table('groups')->where('name', $group['name'])->exists()) {
                continue;
            }

            DB::table('groups')->insert([
                'name' => $group['name'],
                'description' => $group['description'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('groups')->whereIn('name', ['Diagnóstico', 'Programações'])->delete();
    }
};
