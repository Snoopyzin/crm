<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Tipos de atendimento" (which service was performed) used to be a fixed
 * PHP list shared by every tenant -- now each tenant (own database) manages
 * its own list here, grouped by which team (Diagnóstico/Programações)
 * performs it. Seeded below with Volmaster Tech's real service catalog as a
 * starting point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_service_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nome');

            $table->integer('group_id')->unsigned()->nullable();
            $table->foreign('group_id')->references('id')->on('groups')->onDelete('set null');

            $table->timestamps();
        });

        $diagnosticoId = DB::table('groups')->where('name', 'Diagnóstico')->value('id');
        $programacoesId = DB::table('groups')->where('name', 'Programações')->value('id');

        $diagnostico = [
            'Atualização de APCI',
            'Instalação do Techtool 1',
            'Instalação do Techtool 2',
            'Reativação do Techtool',
            'Reparo no Developer',
            'Reparo no Techtool',
            'Reparo arquivos Prog',
            'Formatação',
            'Impact',
            'Atualização de Versão Techtool',
            'Winols',
        ];

        $programacoes = [
            'Prog VECU', 'Prog RCIOM', 'Prog CCIOM', 'Prog FCIOM', 'Prog EMS', 'Prog TECU',
            'Prog VMCU', 'Prog CIOM', 'Prog DDM', 'Prog PDM', 'Prog HMIIOM', 'Prog IC', 'Prog CCM',
            'Atualização de Arquivo', 'Prog APM', 'Kit Performance', 'Redução', 'Pedal Inteligente',
            'Arquivos Euro 6', 'Prog FH Clássico 520', 'Prog Arla VM', 'Prog FLC',
            'Calibragem das Câmeras', 'Prog Bus MID 128 SRT', 'Prog EMS VM Euro 6', 'Prog ACM',
            'Aquivo Slive Trasdata', 'Prog Farol Led', 'Prog EBS', 'Prog ABS', 'Prog MID 128 Ônibus',
            'Desabilitar Sirene - IC', 'Mudar Chassis', 'Câmeras', 'Desabilitar Alarme',
            'Calibragem da TECU', 'Banco de Arquivos', 'Prog Arla FH4', 'Prog Arla FH Clássico',
            'Prog FH4 pra FH Clássico', 'Prog FH Clássico pra FH4', 'Arquivo VM PTO', 'PDFS Euro 6',
            'Prog MID 130 VM', 'Prog SCIM (nova CIOM)', 'Prog VM EURO 6 SRT',
            'Parâmetros freio motor', 'Freio da carreta', 'Arquivo Trasdata', 'PROG VM 330',
            'PROG VM S/REDUÇÃO', 'Download de Arquivo', 'Mudança de parâmetros', 'SHIFT POWER',
            'Prog ECU Da Direção', 'Prog MID 130 VM PTO', 'Prog MID 165 VM', 'Prog MID 128 VM',
            'Arquivo edição WinOLS', 'PROG TPM', 'PEDAL INTELIGENTE EURO 6 FH', 'Prog MID 128',
            'PROG DACU', 'Prog MID 128 SRT', 'Prog FH4 Euro 6 SRT', 'Prog mid 216',
            'Prog MID 130 HD SEM PTO', 'Luz da Injeção', 'Parâmetros SRT FH4 EURO 5', 'Prog MID 140',
            'Prog MID 130 HD COM PTO', 'Prog MID 130', 'ESCAPAMENTO',
            'Parâmetros de performance versão A e B', 'Reiniciar o filtro DPF VM', 'Prog RAM',
            'Prog Trasdata', 'Prog MID 144', 'Prog ECU Alarme',
            'Cabeçote filtro elétrico para mecânico', 'Atualização arquivos VM Euro 6',
            'Venda de módulo', 'Prog VM Euro 6', 'Prog MID 223 VM', 'Parâmetros de led',
            'Prog BUS MID 128', 'Prog BUS MID 130', 'Deixar a TECU FH4 Virgem', 'HD na caixa FH4',
            'Prog VECU VM euro 6', 'Prog MID 140 VM',
        ];

        $now = now();

        foreach ($diagnostico as $nome) {
            DB::table('remote_service_types')->insert([
                'nome' => $nome,
                'group_id' => $diagnosticoId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($programacoes as $nome) {
            DB::table('remote_service_types')->insert([
                'nome' => $nome,
                'group_id' => $programacoesId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_service_types');
    }
};
