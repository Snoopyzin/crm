<?php

namespace Webkul\ServiceOrder\Services\Indicators;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Services\WorkshopSettings;

/**
 * Indicadores da oficina por período: junta o catálogo (metas da tabela de
 * configuração) ao cálculo e à situação (verde/amarelo/vermelho).
 */
class IndicatorService
{
    /** @var array<string, array> */
    protected array $cache = [];

    public function __construct(protected IndicatorCalculator $calculator) {}

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(string $periodo): array
    {
        $from = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth();

        return [$from, $from->copy()->endOfMonth()];
    }

    /**
     * Indicadores do catálogo (semeia os padrões na primeira consulta).
     *
     * @return Collection<int, IndicatorDefinition>
     */
    public function definitions(?string $cargo = null, bool $apenasAtivos = true): Collection
    {
        IndicatorDefinition::ensureDefaultsOnce();

        return IndicatorDefinition::query()
            ->when($cargo, fn ($q) => $q->where('cargo', $cargo))
            ->when($apenasAtivos, fn ($q) => $q->where('ativo', true))
            ->orderBy('ordem')
            ->get();
    }

    public function definition(string $codigo): ?IndicatorDefinition
    {
        IndicatorDefinition::ensureDefaultsOnce();

        return IndicatorDefinition::find($codigo);
    }

    /**
     * Calcula um indicador no mês.
     *
     * @param  array{user_id?: int, mechanic_id?: int}  $scope
     * @return array{definicao: IndicatorDefinition, resultado: IndicatorResult, meta: ?float, situacao: string, fracao: ?float}
     */
    public function evaluate(IndicatorDefinition|string $definition, string $periodo, array $scope = []): array
    {
        $definition = is_string($definition) ? $this->definition($definition) : $definition;

        $key = $definition->codigo.'|'.$periodo.'|'.json_encode($scope);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        [$from, $to] = $this->period($periodo);

        $resultado = $this->resultFor($definition, $periodo, $from, $to, $scope);
        $origem = 'sistema';

        // Sem dado no sistema (mês anterior à virada): vale o histórico importado das planilhas.
        if (! $resultado->hasData() && $scope === []) {
            $historico = DB::table('indicator_snapshots')->where('periodo', $periodo)->where('codigo', $definition->codigo)
                ->where('escopo', 'oficina')->where('origem', 'planilha')->first();

            if ($historico) {
                $resultado = new IndicatorResult((float) $historico->realizado, null, null, [], 'Valor importado da planilha de medição.');
                $origem = 'planilha';
            }
        }

        $meta = $this->metaFor($definition, $periodo);
        $situacao = IndicatorStatus::of($definition->tipo_meta, $meta, $resultado->realizado);

        return $this->cache[$key] = [
            'definicao' => $definition,
            'resultado' => $resultado,
            'meta' => $meta,
            'situacao' => $situacao,
            'fracao' => IndicatorStatus::fraction($situacao),
            'origem' => $origem,
        ];
    }

    /**
     * Mesma tendência de trend(), com a origem de cada mês (sistema ou planilha).
     *
     * @return array<string, array{valor: ?float, origem: string}>
     */
    public function trendDetalhado(string $codigo, string $periodo, int $months = 3): array
    {
        $result = [];
        $cursor = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth()->subMonths($months);

        for ($i = 0; $i < $months; $i++) {
            $ym = $cursor->format('Y-m');
            $avaliacao = $this->evaluate($codigo, $ym);
            $result[$ym] = ['valor' => $avaliacao['resultado']->realizado, 'origem' => $avaliacao['origem']];
            $cursor->addMonth();
        }

        return $result;
    }

    /**
     * Todos os indicadores do mês (ou de um cargo), com a situação de cada um.
     *
     * @return Collection<string, array>
     */
    public function month(string $periodo, ?string $cargo = null, array $scope = []): Collection
    {
        return $this->definitions($cargo)->mapWithKeys(fn ($definition) => [$definition->codigo => $this->evaluate($definition, $periodo, $scope)]);
    }

    /**
     * Valores dos últimos meses (do mais antigo ao mais recente), para a tendência.
     *
     * @return array<string, ?float>
     */
    public function trend(string $codigo, string $periodo, int $months = 3): array
    {
        $result = [];
        $cursor = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth()->subMonths($months);

        for ($i = 0; $i < $months; $i++) {
            $ym = $cursor->format('Y-m');
            $result[$ym] = $this->evaluate($codigo, $ym)['resultado']->realizado;
            $cursor->addMonth();
        }

        return $result;
    }

    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    /**
     * Meta em vigor. DON-05 ("queda mês a mês") compara com o mês anterior.
     */
    protected function metaFor(IndicatorDefinition $definition, string $periodo): ?float
    {
        if ($definition->codigo === 'DON-05') {
            // Só o resultado do mês anterior (sem a meta dele, que seria outra volta para trás).
            $anterior = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth()->subMonth();

            return $this->resultFor($definition, $anterior->format('Y-m'), $anterior->copy(), $anterior->copy()->endOfMonth(), [])->realizado;
        }

        // As horas semanais do dono vêm da Ficha de Enquadramento (padrão 8 h).
        if ($definition->codigo === 'DON-01' && $definition->meta_oficina === null) {
            return (float) WorkshopSettings::get('dono_horas_prospeccao', 8);
        }

        return $definition->meta();
    }

    protected function resultFor(IndicatorDefinition $definition, string $periodo, Carbon $from, Carbon $to, array $scope): IndicatorResult
    {
        return match ($definition->codigo) {
            'GER-01' => $this->noAlvo($periodo),
            'GER-07' => $this->vermelhosComPlano($periodo),
            default => $this->calculator->calculate($definition->codigo, $from, $to, $scope),
        };
    }

    /**
     * GER-01: indicadores "No alvo" ÷ indicadores com meta e dado no mês.
     */
    protected function noAlvo(string $periodo): IndicatorResult
    {
        $linhas = $this->definitions()
            ->reject(fn ($d) => in_array($d->codigo, ['GER-01', 'GER-07'], true))
            ->map(fn ($d) => $this->evaluate($d, $periodo))
            ->filter(fn ($linha) => in_array($linha['situacao'], [IndicatorStatus::VERDE, IndicatorStatus::AMARELO, IndicatorStatus::VERMELHO], true));

        return IndicatorResult::ratio($linhas->where('situacao', IndicatorStatus::VERDE)->count(), $linhas->count());
    }

    /**
     * GER-07: indicadores vermelhos com plano de ação ÷ indicadores vermelhos.
     */
    protected function vermelhosComPlano(string $periodo): IndicatorResult
    {
        $vermelhos = $this->definitions()
            ->reject(fn ($d) => in_array($d->codigo, ['GER-01', 'GER-07'], true))
            ->filter(fn ($d) => $this->evaluate($d, $periodo)['situacao'] === IndicatorStatus::VERMELHO);

        if ($vermelhos->isEmpty()) {
            return IndicatorResult::none('Nenhum indicador vermelho no mês.');
        }

        [, $to] = $this->period($periodo);

        $planos = MentoriaRegistro::vigentes()->doTipo('plano_acao')->where('ocorrido_em', '<=', $to)->get()
            ->map(fn ($plano) => $plano->dados_json['indicador'] ?? null)
            ->filter();

        $comPlano = $vermelhos->filter(fn ($d) => $planos->contains($d->codigo));

        return IndicatorResult::ratio($comPlano->count(), $vermelhos->count());
    }
}
