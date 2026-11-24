<?php

namespace Webkul\ServiceOrder\Services\Bonus;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\WorkshopSettings;

/**
 * Fechamento mensal (capítulo 9.2):
 *
 * - dia 1: congela os indicadores do mês, detecta travas automáticas e calcula
 *   as notas de bônus;
 * - até o 3º dia útil: o gerente confere, confirma travas e dá o feedback;
 * - até o 5º dia útil: o dono aprova o bônus e a folha;
 * - depois da aprovação: exportação das variáveis da folha (CSV).
 *
 * O job de 1 minuto chama runIfDue(); se o cron falhar no dia 1, o fechamento
 * acontece na primeira passada seguinte.
 */
class FechamentoService
{
    public function __construct(
        protected IndicatorService $indicators,
        protected TravaService $travas,
        protected BonusService $bonus,
    ) {}

    /**
     * Fecha o mês anterior se ainda não foi fechado.
     */
    public function runIfDue(?Carbon $agora = null): bool
    {
        $agora ??= now();
        $periodo = $agora->copy()->subMonthNoOverflow()->format('Y-m');

        if ($this->fechado($periodo)) {
            return false;
        }

        // Sem colaboradores cadastrados não há o que apurar: espera o cadastro.
        if (! Colaborador::exists()) {
            return false;
        }

        // Mês anterior ao início da mentoria: nada a fechar.
        $inicio = WorkshopSettings::get('inicio_mentoria');

        if ($inicio && $periodo < Carbon::parse($inicio)->format('Y-m')) {
            WorkshopSettings::set('fechamento_'.$periodo, 'anterior_a_mentoria');

            return false;
        }

        $this->fechar($periodo, $agora);

        return true;
    }

    public function fechado(string $periodo): bool
    {
        return (bool) WorkshopSettings::get('fechamento_'.$periodo);
    }

    /**
     * Congela os indicadores, detecta as travas e apura os bônus do mês.
     *
     * @return array{indicadores: int, travas: int, apuracoes: int}
     */
    public function fechar(string $periodo, ?Carbon $agora = null): array
    {
        $agora ??= now();

        $indicadores = $this->congelarIndicadores($periodo, $agora);
        $travas = $this->travas->detectar($periodo);
        $apuracoes = $this->bonus->apurar($periodo)->count();

        WorkshopSettings::set('fechamento_'.$periodo, $agora->toIso8601String());

        return ['indicadores' => $indicadores, 'travas' => $travas, 'apuracoes' => $apuracoes];
    }

    /**
     * Grava os valores dos indicadores do mês (não mudam mais depois do dia 1).
     */
    public function congelarIndicadores(string $periodo, ?Carbon $agora = null): int
    {
        $agora ??= now();
        $linhas = [];

        foreach ($this->indicators->month($periodo) as $codigo => $linha) {
            $resultado = $linha['resultado'];

            $linhas[] = [
                'periodo' => $periodo,
                'codigo' => $codigo,
                'escopo' => 'oficina',
                'escopo_id' => 0,
                'realizado' => $resultado->realizado,
                'numerador' => $resultado->numerador,
                'denominador' => $resultado->denominador,
                'meta' => $linha['meta'],
                'situacao' => $linha['situacao'],
                'calculado_em' => $agora,
            ];
        }

        if ($linhas) {
            DB::table('indicator_snapshots')->upsert($linhas, ['periodo', 'codigo', 'escopo', 'escopo_id'], ['realizado', 'numerador', 'denominador', 'meta', 'situacao', 'calculado_em']);
        }

        return count($linhas);
    }

    /**
     * Gerente fecha o Painel do Dono do mês (GER-11 mede o prazo).
     */
    public function consolidarPainel(string $periodo, ?int $usuarioId = null): MentoriaRegistro
    {
        return MentoriaRegistro::record('painel_consolidado', ['dados_json' => ['periodo' => $periodo]], now(), $usuarioId);
    }
}
