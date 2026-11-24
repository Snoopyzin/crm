<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\ServiceOrder\Models\BarrierInstance;
use Webkul\ServiceOrder\Models\BarrierRule;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorStatus;
use Webkul\ServiceOrder\Services\Indicators\PainelDonoService;

/**
 * Alertas do capítulo 7.2 que não são um prazo contado a partir de um passo
 * da OS: OS parada, indicador vermelho sem plano de ação, RH a vencer e cargo
 * sem responsável. Rodam no máximo uma vez por hora (o job é a cada minuto).
 *
 * Todos vão ao gerente -- nunca direto ao dono (regra do dono blindado).
 */
class PeriodicAlerts
{
    public const OS_PARADA_DIAS = 3;

    public const VERMELHO_SEM_PLANO_DIAS = 7;

    public const RH_A_VENCER_DIAS = 30;

    public function __construct(
        protected AlertService $alerts,
        protected PainelDonoService $painel,
        protected IndicatorService $indicators,
    ) {}

    /**
     * @return int Quantidade de alertas criados (0 quando ainda não é a hora)
     */
    public function runIfDue(?Carbon $now = null): int
    {
        $now ??= now();
        $last = WorkshopSettings::get('alertas_periodicos_em');

        if ($last && Carbon::parse($last)->gt($now->copy()->subHour())) {
            return 0;
        }

        WorkshopSettings::set('alertas_periodicos_em', $now->toIso8601String());

        return $this->run($now);
    }

    public function run(?Carbon $now = null): int
    {
        $now ??= now();

        return $this->osParadas($now)
            + $this->indicadoresVermelhosSemPlano($now)
            + $this->rhAVencer($now)
            + $this->cargosSemResponsavel($now)
            + $this->resumoDoDono($now);
    }

    /**
     * Resumo opcional (desligado por padrão) das barreiras vencidas, a cada 2 h,
     * para o dono que pediu. Sem valores e sem dados de crédito.
     */
    public function resumoDoDono(Carbon $now): int
    {
        if (! WorkshopSettings::get('dono_resumo_barreiras', false)) {
            return 0;
        }

        $ultimo = WorkshopSettings::get('dono_resumo_em');

        if ($ultimo && Carbon::parse($ultimo)->gt($now->copy()->subHours(2))) {
            return 0;
        }

        $vencidas = BarrierInstance::where('status', BarrierInstance::STATUS_VENCIDA)->get();

        if ($vencidas->isEmpty()) {
            return 0;
        }

        WorkshopSettings::set('dono_resumo_em', $now->toIso8601String());

        $nomes = BarrierRule::pluck('nome', 'codigo');
        $porBarreira = $vencidas->groupBy('barrier_code')->map(fn ($grupo, $codigo) => ($nomes[$codigo] ?? $codigo).': '.$grupo->count());

        return $this->alerts->raise(
            'resumo_barreiras',
            'Resumo das barreiras vencidas',
            'RESUMO - '.$vencidas->count().' barreira(s) vencida(s) - '.$porBarreira->implode('; ').'.',
            ['proprietario'],
            permitirDono: true,
        )->count();
    }

    /**
     * OS aberta há mais de 3 dias sem mudar de etapa: o gerente (e o relatório
     * diário) ficam sabendo. Um aviso por OS por dia.
     */
    public function osParadas(Carbon $now): int
    {
        $abertas = ServiceOrder::query()
            ->whereNotIn('quotes.workshop_status', [ServiceOrder::STAGE_CONCLUIDA, ServiceOrder::STAGE_CANCELADA])
            ->pluck('quotes.id')
            ->all();

        $criados = 0;

        foreach ($this->painel->idsParados($abertas, self::OS_PARADA_DIAS) as $id) {
            $os = ServiceOrder::with(['vehicle', 'person'])->find($id);

            if (! $os) {
                continue;
            }

            $criados += $this->alerts->raise(
                'os_parada',
                'OS parada',
                "ALERTA - OS parada. OS #{$os->id} - ".($os->vehicle?->placa ?? '-').' - '.($os->person?->name ?? '-').'. Sem mudar de etapa há mais de '.self::OS_PARADA_DIAS." dias (etapa: {$os->workshop_status}).",
                ['gerente'],
                $os,
                unicoPorDia: true,
            )->count();
        }

        return $criados;
    }

    /**
     * Indicador "Abaixo" há mais de 7 dias sem plano de ação: avisa o gerente.
     * O "vermelho desde" é guardado nas configurações, a cada passada.
     */
    public function indicadoresVermelhosSemPlano(Carbon $now): int
    {
        $periodo = $now->format('Y-m');
        $desde = (array) WorkshopSettings::get('vermelho_desde', []);
        $vermelhos = [];

        foreach ($this->indicators->month($periodo) as $codigo => $linha) {
            if ($linha['situacao'] === IndicatorStatus::VERMELHO && ! in_array($codigo, ['GER-01', 'GER-07'], true)) {
                $vermelhos[$codigo] = $desde[$codigo] ?? $now->toDateString();
            }
        }

        WorkshopSettings::set('vermelho_desde', $vermelhos);

        $planos = MentoriaRegistro::vigentes()->doTipo('plano_acao')->where('ocorrido_em', '>=', $now->copy()->subDays(60))->get()
            ->map(fn ($plano) => $plano->dados_json['indicador'] ?? null)
            ->filter();

        $criados = 0;

        foreach ($vermelhos as $codigo => $dia) {
            if (Carbon::parse($dia)->gt($now->copy()->subDays(self::VERMELHO_SEM_PLANO_DIAS)) || $planos->contains($codigo)) {
                continue;
            }

            // Um lembrete por indicador por semana.
            $jaAvisado = WorkshopAlert::where('barrier_code', 'indicador_sem_plano')
                ->where('mensagem', 'like', "%{$codigo}%")
                ->where('disparado_em', '>=', $now->copy()->subDays(7))
                ->exists();

            if ($jaAvisado) {
                continue;
            }

            $nome = $this->indicators->definition($codigo)?->nome;

            $criados += $this->alerts->raise(
                'indicador_sem_plano',
                'Indicador vermelho sem plano de ação',
                "ALERTA - O indicador {$codigo} ({$nome}) está abaixo da meta há mais de ".self::VERMELHO_SEM_PLANO_DIAS.' dias e não tem plano de ação.',
                ['gerente'],
            )->count();
        }

        return $criados;
    }

    /**
     * Férias, exame periódico ou EPI vencendo em 30 dias (cadastro de colaboradores).
     */
    public function rhAVencer(Carbon $now): int
    {
        if (! Schema::hasTable('colaboradores')) {
            return 0;
        }

        $criados = 0;
        $limite = $now->copy()->addDays(self::RH_A_VENCER_DIAS)->toDateString();

        $itens = [
            'ferias_vencimento' => 'Férias',
            'exame_periodico_vencimento' => 'Exame periódico',
            'epi_vencimento' => 'Ficha de EPI',
        ];

        foreach (DB::table('colaboradores')->where('ativo', true)->get() as $colaborador) {
            foreach ($itens as $campo => $rotulo) {
                if (! $colaborador->{$campo} || $colaborador->{$campo} > $limite) {
                    continue;
                }

                $vencido = $colaborador->{$campo} < $now->toDateString();

                $criados += $this->alerts->raise(
                    'rh_a_vencer',
                    'RH a vencer',
                    "ALERTA - {$rotulo} de {$colaborador->nome} ".($vencido ? 'vencido' : 'vence').' em '.Carbon::parse($colaborador->{$campo})->format('d/m/Y').'.',
                    ['gerente'],
                    unicoPorDia: true,
                )->count();
            }
        }

        return $criados;
    }

    /**
     * Pendência da Ficha de Enquadramento (cargo sem responsável) que passou do prazo.
     */
    public function cargosSemResponsavel(Carbon $now): int
    {
        if (! Schema::hasTable('cargos_pendentes')) {
            return 0;
        }

        $criados = 0;

        foreach (DB::table('cargos_pendentes')->whereNull('resolvido_em')->whereNotNull('prazo')->where('prazo', '<', $now->toDateString())->get() as $pendencia) {
            $criados += $this->alerts->raise(
                'cargo_sem_responsavel',
                'Cargo sem responsável',
                "ALERTA - O cargo {$pendencia->cargo} segue sem responsável e a pendência da Ficha de Enquadramento passou do prazo.",
                ['gerente'],
                unicoPorDia: false,
            )->count();
        }

        return $criados;
    }
}
