<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Old workshop_status value => new 9-stage vocabulary. Applied to
     * existing rows only -- `workshop_status` keeps the same column name
     * and type, just a wider set of allowed values.
     */
    protected array $statusMap = [
        'Aberta' => 'Ordem de Serviço',
        'Autorizado' => 'Negociação',
        'Em Andamento' => 'Em Execução',
        'Aguardando Peças' => 'Em Execução',
        'Concluída' => 'Concluída',
        'Cancelada' => 'Cancelada',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('tempo_servico')->nullable()->after('data_conclusao');

            $table->text('diagnostico')->nullable()->after('tempo_servico');
            $table->integer('diagnostico_mechanic_id')->unsigned()->nullable()->after('diagnostico');
            $table->foreign('diagnostico_mechanic_id')->references('id')->on('mechanics')->onDelete('set null');
            $table->dateTime('diagnostico_concluido_em')->nullable()->after('diagnostico_mechanic_id');

            $table->text('negociacao_observacoes')->nullable()->after('diagnostico_concluido_em');

            $table->integer('teste_mechanic_id')->unsigned()->nullable()->after('negociacao_observacoes');
            $table->foreign('teste_mechanic_id')->references('id')->on('mechanics')->onDelete('set null');
            $table->dateTime('teste_iniciado_em')->nullable()->after('teste_mechanic_id');
            $table->dateTime('teste_finalizado_em')->nullable()->after('teste_iniciado_em');
            $table->text('teste_observacoes')->nullable()->after('teste_finalizado_em');
        });

        foreach ($this->statusMap as $old => $new) {
            DB::table('quotes')->where('workshop_status', $old)->update(['workshop_status' => $new]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $reverseMap = [
            'Ordem de Serviço' => 'Aberta',
            'Negociação' => 'Autorizado',
            'Em Execução' => 'Em Andamento',
            'Concluída' => 'Concluída',
            'Cancelada' => 'Cancelada',
        ];

        foreach ($reverseMap as $new => $old) {
            DB::table('quotes')->where('workshop_status', $new)->update(['workshop_status' => $old]);
        }

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropForeign(['diagnostico_mechanic_id']);
            $table->dropForeign(['teste_mechanic_id']);

            $table->dropColumn([
                'tempo_servico',
                'diagnostico',
                'diagnostico_mechanic_id',
                'diagnostico_concluido_em',
                'negociacao_observacoes',
                'teste_mechanic_id',
                'teste_iniciado_em',
                'teste_finalizado_em',
                'teste_observacoes',
            ]);
        });
    }
};
