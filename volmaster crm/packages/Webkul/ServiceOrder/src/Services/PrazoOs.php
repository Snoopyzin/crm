<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prazo de cada OS para o card "OS em andamento" do Início: no prazo (verde),
 * estourado (vermelho) ou sem prazo definido (cinza).
 *
 * Fonte do prazo, nesta ordem:
 *  1. Piloto ligado: a barreira aberta que vence primeiro (ex.: "Orçamento em
 *     até 2 h"). Estourou quando passou do vencimento, mesmo que o relógio
 *     de 1 minuto ainda não tenha marcado a barreira como vencida.
 *  2. Previsão de entrega da OS (vale até o fim do dia marcado ou, se a OS tem
 *     horário, até aquela hora: veículo que entra e sai no mesmo dia).
 *  3. Nenhuma das duas: sem prazo, e a cor não afirma nada.
 */
class PrazoOs
{
    public const NO_PRAZO = 'no_prazo';

    public const ESTOURADO = 'estourado';

    public const SEM_PRAZO = 'sem_prazo';

    /**
     * Anexa o atributo "prazo" a cada OS da lista (e ele vai junto no JSON).
     *
     * @param  Collection<int, Model>  $ordens
     * @return Collection<int, Model>
     */
    public static function aplicar(Collection $ordens): Collection
    {
        if ($ordens->isEmpty()) {
            return $ordens;
        }

        $barreiras = static::barreirasAbertas($ordens->pluck('id')->all());

        foreach ($ordens as $ordem) {
            $ordem->setAttribute('prazo', static::calcular($ordem->data_previsao, $barreiras[$ordem->id] ?? null, null, $ordem->getAttribute('hora_previsao')));
        }

        return $ordens;
    }

    /**
     * @param  object|null  $barreira  linha de barrier_instances (com "nome" da regra)
     * @return array{estado: string, origem: string|null, vence_em: string|null, texto: string}
     */
    public static function calcular(mixed $previsao, ?object $barreira = null, ?Carbon $agora = null, mixed $hora = null): array
    {
        $agora ??= now();

        if ($barreira && $barreira->vence_em) {
            $vence = Carbon::parse($barreira->vence_em);
            $estourado = $barreira->status === 'vencida' || (int) $barreira->nivel_atual > 0 || $agora->greaterThanOrEqualTo($vence);
            $nome = $barreira->nome ?: 'Prazo da etapa';

            return [
                'estado' => $estourado ? static::ESTOURADO : static::NO_PRAZO,
                'origem' => 'barreira',
                'vence_em' => $vence->toIso8601String(),
                'texto' => $nome.($estourado ? ': prazo estourado em ' : ': vence em ').$vence->format('d/m H:i'),
            ];
        }

        if ($previsao) {
            // Com horário (veículo que entra e sai no mesmo dia) vence naquela
            // hora; só com a data, vale até o fim do dia.
            $vence = PrevisaoOs::momento($previsao, $hora);
            $estourado = $agora->greaterThan($vence);

            return [
                'estado' => $estourado ? static::ESTOURADO : static::NO_PRAZO,
                'origem' => 'previsao',
                'vence_em' => $vence->toIso8601String(),
                'texto' => 'Previsão de entrega '.($estourado ? '(atrasada) ' : '').PrevisaoOs::texto($previsao, $hora),
            ];
        }

        return [
            'estado' => static::SEM_PRAZO,
            'origem' => null,
            'vence_em' => null,
            'texto' => 'Sem prazo definido',
        ];
    }

    /**
     * A barreira aberta que vence primeiro, por OS (só com o piloto ligado).
     *
     * @param  int[]  $ids
     * @return array<int, object>
     */
    protected static function barreirasAbertas(array $ids): array
    {
        if (! StageGate::active() || ! Schema::hasTable('barrier_instances')) {
            return [];
        }

        return DB::table('barrier_instances as bi')
            ->leftJoin('barrier_rules as br', 'br.codigo', '=', 'bi.barrier_code')
            ->whereIn('bi.service_order_id', $ids)
            ->where('bi.status', '<>', 'cumprida')
            ->whereNotNull('bi.vence_em')
            ->orderBy('bi.vence_em')
            ->get(['bi.service_order_id', 'bi.vence_em', 'bi.status', 'bi.nivel_atual', 'br.nome'])
            ->unique('service_order_id')
            ->keyBy('service_order_id')
            ->all();
    }
}
