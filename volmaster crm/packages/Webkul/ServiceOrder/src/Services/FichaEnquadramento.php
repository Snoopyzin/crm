<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Models\Colaborador;

/**
 * Leitura da Ficha de Enquadramento (capítulo 3.1): parâmetros da oficina,
 * cargos ocupados e cargos sem responsável, e a fase da mentoria (que define
 * os pesos do bônus). Os valores moram em WorkshopSettings.
 */
class FichaEnquadramento
{
    public const PORTES = [
        'ate_6' => 'Até 6 colaboradores',
        '7_a_15' => '7 a 15 colaboradores',
        '16_ou_mais' => '16 ou mais colaboradores',
    ];

    /** Cargos que a oficina precisa ter ocupados (a zeladoria é opcional). */
    public const CARGOS_OBRIGATORIOS = ['proprietario', 'gerente', 'chefe', 'consultor', 'tecnico', 'pecas', 'financeiro'];

    /**
     * Mês da mentoria (1 = mês do início) para um período AAAA-MM; null sem data de início.
     */
    public static function mesDaMentoria(string $periodo): ?int
    {
        $inicio = WorkshopSettings::get('inicio_mentoria');

        if (! $inicio) {
            return null;
        }

        $primeiro = Carbon::parse($inicio)->startOfMonth();
        $mes = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth();

        return $mes->lt($primeiro) ? null : (int) $primeiro->diffInMonths($mes) + 1;
    }

    /**
     * Fase do bônus: meses 1 e 2 = fase 1 (processo); do mês 3 em diante =
     * fase 2 (processo + resultado). Sem data de início, vale a fase 1.
     */
    public static function fase(string $periodo): int
    {
        $mes = static::mesDaMentoria($periodo);

        return $mes !== null && $mes >= 3 ? 2 : 1;
    }

    /**
     * Cargos sem nenhum colaborador ativo.
     *
     * @return string[]
     */
    public function cargosSemResponsavel(): array
    {
        $ocupados = Colaborador::ativos()->get()->flatMap(fn ($c) => $c->cargosLista())->unique();

        return array_values(array_diff(self::CARGOS_OBRIGATORIOS, $ocupados->all()));
    }

    /**
     * Tabela cargo × colaboradores.
     *
     * @return array<string, list<string>>
     */
    public function cargosOcupados(): array
    {
        $mapa = [];

        foreach (Colaborador::ativos()->orderBy('nome')->get() as $colaborador) {
            foreach ($colaborador->cargosLista() as $cargo) {
                $mapa[$cargo][] = $colaborador->nome;
            }
        }

        return $mapa;
    }

    /**
     * Pendências em aberto (cargo sem responsável com ação e prazo).
     */
    public function pendencias(): Collection
    {
        return DB::table('cargos_pendentes')->whereNull('resolvido_em')->orderBy('prazo')->get();
    }
}
