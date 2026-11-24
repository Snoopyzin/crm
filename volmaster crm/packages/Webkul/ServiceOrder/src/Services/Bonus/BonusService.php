<?php

namespace Webkul\ServiceOrder\Services\Bonus;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\BonusApuracao;
use Webkul\ServiceOrder\Models\BonusMarcador;
use Webkul\ServiceOrder\Models\BonusTrava;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\BusinessHours;
use Webkul\ServiceOrder\Services\FichaEnquadramento;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorStatus;
use Webkul\ServiceOrder\Services\ModoTeste;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\User;

/**
 * Motor de bônus (capítulo 6). Uma vez por mês, por colaborador e por cargo:
 *
 * 1. fase: meses 1 e 2 desde o início da mentoria = Fase 1 (processo); do mês
 *    3 em diante = Fase 2 (processo + resultado);
 * 2. cada marcador vale pontos conforme a situação do indicador (peso inteiro
 *    no alvo, metade em "atenção", zero abaixo); sem dado no mês, o marcador
 *    sai e os pesos dos demais são reescalonados para somar 100;
 * 3. nota = soma dos pontos (0 a 100); faixa: 90+ paga 100%, 80+ paga 70%,
 *    70+ paga 40%, abaixo zera;
 * 4. qualquer trava confirmada no mês zera o bônus, seja qual for a nota;
 * 5. valor = salário base × % cheio do cargo (Ficha) × % da faixa.
 */
class BonusService
{
    public function __construct(protected IndicatorService $indicators) {}

    /**
     * Cálculo do mês de um colaborador num cargo (não grava nada).
     *
     * @return array<string, mixed>
     */
    public function calcular(Colaborador $colaborador, string $cargo, string $periodo): array
    {
        BonusMarcador::ensureDefaultsOnce();

        $fase = $cargo === 'zeladoria' ? 1 : FichaEnquadramento::fase($periodo);
        $scope = $this->escopo($colaborador, $cargo);

        $base = [
            'colaborador_id' => $colaborador->id,
            'cargo' => $cargo,
            'fase' => $fase,
            'nota' => null,
            'faixa_percentual' => 0,
            'zerado_por_trava' => false,
            'detalhe' => [],
            'motivo_resultado' => null,
            'motivo' => null,
        ];

        // Consultor e técnico são medidos pelas próprias OS: precisam estar ligados ao usuário/técnico.
        if ($scope === null) {
            return array_merge($base, ['motivo' => 'Ligue o colaborador ao '.($cargo === 'tecnico' ? 'técnico' : 'usuário').' do sistema para medir o cargo.']);
        }

        [$gatilhoAtingido, $motivoResultado] = $this->gatilho($periodo, $fase);

        $marcadores = BonusMarcador::where('cargo', $cargo)->where('fase', $fase)->where('ativo', true)->orderBy('id')->get();

        $detalhe = [];
        $pesoTotal = 0;
        $pontos = 0.0;

        foreach ($marcadores as $marcador) {
            $linha = $this->avaliarMarcador($marcador, $periodo, $scope);

            // Sem dado (ou meta em branco) no mês: o marcador sai do cálculo.
            if ($linha['fracao'] === null) {
                $linha['pontos'] = null;
                $detalhe[] = $linha;

                continue;
            }

            if ($marcador->resultado && $fase === 2 && ! $gatilhoAtingido) {
                $linha['fracao'] = 0.0;
                $linha['obs'] = $motivoResultado;
            }

            $linha['pontos'] = $marcador->peso * $linha['fracao'];
            $pesoTotal += $marcador->peso;
            $pontos += $linha['pontos'];
            $detalhe[] = $linha;
        }

        // Pesos reescalonados para somar 100.
        $nota = $pesoTotal > 0 ? round($pontos / $pesoTotal * 100, 1) : null;

        foreach ($detalhe as &$linha) {
            $linha['pontos_ajustados'] = $linha['pontos'] === null || $pesoTotal <= 0 ? null : round($linha['pontos'] / $pesoTotal * 100, 1);
            $linha['peso_ajustado'] = $linha['pontos'] === null || $pesoTotal <= 0 ? null : round($linha['peso'] / $pesoTotal * 100, 1);
        }
        unset($linha);

        $faixa = $nota === null ? 0 : $this->faixa($nota);

        $travado = BonusTrava::where('periodo', $periodo)
            ->where('colaborador_id', $colaborador->id)
            ->where('cargo', $cargo)
            ->where('status', BonusTrava::CONFIRMADA)
            ->exists();

        return array_merge($base, [
            'nota' => $nota,
            'faixa_percentual' => $travado ? 0 : $faixa,
            'zerado_por_trava' => $travado,
            'detalhe' => $detalhe,
            'motivo_resultado' => $fase === 2 && ! $gatilhoAtingido ? $motivoResultado : null,
            'motivo' => $nota === null ? 'Nenhum indicador do cargo tem dado neste mês.' : null,
        ]);
    }

    /**
     * Faixa de pagamento pela nota.
     */
    public function faixa(float $nota): int
    {
        foreach (BonusCatalog::FAIXAS as $minimo => $percentual) {
            if ($nota >= $minimo) {
                return $percentual;
            }
        }

        return 0;
    }

    /**
     * Calcula e grava (ou atualiza) as apurações do mês de todos os
     * colaboradores ativos, por cargo. Apuração aprovada não é mexida.
     *
     * @return Collection<int, BonusApuracao>
     */
    public function apurar(string $periodo): Collection
    {
        $resultado = collect();

        $colaboradores = Colaborador::query()
            ->where('ativo', true)
            ->orWhere(fn ($q) => $q->whereNotNull('desligamento_em')->where('desligamento_em', '>=', Carbon::createFromFormat('Y-m', $periodo)->startOfMonth()->toDateString()))
            ->get();

        foreach ($colaboradores as $colaborador) {
            foreach ($colaborador->cargosLista() as $cargo) {
                // O proprietário não entra no bônus (aprova o dos outros).
                if ($cargo === 'proprietario') {
                    continue;
                }

                $existente = BonusApuracao::where('periodo', $periodo)->where('colaborador_id', $colaborador->id)->where('cargo', $cargo)->first();

                if ($existente?->aprovada()) {
                    $resultado->push($existente);

                    continue;
                }

                $resultado->push($this->gravar($colaborador, $cargo, $periodo, $existente));
            }
        }

        return $resultado;
    }

    /**
     * Recalcula as apurações (ainda não aprovadas) de um colaborador no mês --
     * por exemplo depois de uma trava confirmada.
     */
    public function apurarColaborador(Colaborador $colaborador, string $periodo): void
    {
        foreach ($colaborador->cargosLista() as $cargo) {
            if ($cargo === 'proprietario') {
                continue;
            }

            $existente = BonusApuracao::where('periodo', $periodo)->where('colaborador_id', $colaborador->id)->where('cargo', $cargo)->first();

            if ($existente && ! $existente->aprovada()) {
                $this->gravar($colaborador, $cargo, $periodo, $existente);
            }
        }
    }

    protected function gravar(Colaborador $colaborador, string $cargo, string $periodo, ?BonusApuracao $existente): BonusApuracao
    {
        $calculo = $this->calcular($colaborador, $cargo, $periodo);

        $percentual = (float) (WorkshopSettings::get('bonus_cheio_percentual', [])[$cargo] ?? 0) ?: null;
        $salario = $colaborador->salario_base;
        $valor = $salario !== null && $percentual !== null
            ? round($salario * $percentual / 100 * $calculo['faixa_percentual'] / 100, 2)
            : null;

        $dados = [
            'fase' => $calculo['fase'],
            'nota' => $calculo['nota'],
            'faixa_percentual' => $calculo['faixa_percentual'],
            'zerado_por_trava' => $calculo['zerado_por_trava'],
            'salario_base' => $salario,
            'percentual_cheio' => $percentual,
            'valor' => $valor,
            'detalhe_json' => $calculo['detalhe'],
            'motivo_resultado' => $calculo['motivo_resultado'] ?? $calculo['motivo'],
            'calculado_em' => now(),
        ];

        if ($existente) {
            // Recalcular depois de revisado volta para "calculado": o gerente confere de novo.
            $existente->update($dados + ['status' => BonusApuracao::CALCULADO]);

            return $existente->refresh();
        }

        return BonusApuracao::create($dados + [
            'periodo' => $periodo,
            'colaborador_id' => $colaborador->id,
            'cargo' => $cargo,
            'status' => BonusApuracao::CALCULADO,
        ]);
    }

    /**
     * Gerente revisa a nota e dá o feedback individual.
     */
    public function revisar(BonusApuracao $apuracao, User $gerente, string $feedback): BonusApuracao
    {
        $this->exigirNivel($gerente, 1, 'Só o gerente revisa a nota do bônus.');

        if ($apuracao->aprovada()) {
            throw ValidationException::withMessages(['bonus' => ['Este bônus já foi aprovado.']]);
        }

        if (trim($feedback) === '') {
            throw ValidationException::withMessages(['feedback' => ['Escreva o feedback individual do colaborador.']]);
        }

        $apuracao->update([
            'status' => BonusApuracao::REVISADO,
            'feedback' => $feedback,
            'revisado_por' => $gerente->id,
            'revisado_em' => now(),
        ]);

        return $apuracao->refresh();
    }

    /**
     * Registro de ciência do colaborador sobre a própria nota.
     */
    public function registrarCiencia(BonusApuracao $apuracao, User $usuario): BonusApuracao
    {
        if ($apuracao->colaborador?->user_id !== $usuario->id) {
            throw ValidationException::withMessages(['bonus' => ['Só o próprio colaborador registra a ciência da nota.']]);
        }

        $apuracao->update(['ciencia_colaborador_em' => now()]);

        return $apuracao->refresh();
    }

    /**
     * O dono aprova até o 5º dia útil do mês seguinte, depois da revisão do gerente.
     */
    public function aprovar(BonusApuracao $apuracao, User $dono, ?Carbon $agora = null): BonusApuracao
    {
        $this->exigirNivel($dono, 2, 'Só o dono aprova o bônus.');

        $agora ??= now();

        if ($apuracao->aprovada()) {
            return $apuracao;
        }

        if ($apuracao->status !== BonusApuracao::REVISADO) {
            throw ValidationException::withMessages(['bonus' => ['O gerente precisa revisar a nota e dar o feedback antes da aprovação.']]);
        }

        if (! $this->janelaDeAprovacao($apuracao->periodo, $agora)) {
            throw ValidationException::withMessages(['bonus' => ['A aprovação do bônus vai do dia 1 ao 5º dia útil do mês seguinte.']]);
        }

        $apuracao->update(['status' => BonusApuracao::APROVADO, 'aprovado_por' => $dono->id, 'aprovado_em' => $agora]);

        return $apuracao->refresh();
    }

    /**
     * Depois de aprovado, o bônus só muda com estorno justificado: o registro
     * fica na trilha de auditoria e a apuração volta para conferência.
     */
    public function estornar(BonusApuracao $apuracao, User $dono, string $justificativa): BonusApuracao
    {
        $this->exigirNivel($dono, 2, 'Só o dono estorna um bônus aprovado.');

        if (! $apuracao->aprovada()) {
            throw ValidationException::withMessages(['bonus' => ['Só se estorna um bônus já aprovado.']]);
        }

        if (trim($justificativa) === '') {
            throw ValidationException::withMessages(['justificativa' => ['O estorno exige justificativa.']]);
        }

        MentoriaRegistro::record('bonus_estorno', [
            'user_id' => $apuracao->colaborador?->user_id,
            'valor' => $apuracao->valor,
            'observacao' => $justificativa,
            'justificativa' => $justificativa,
            'dados_json' => ['apuracao_id' => $apuracao->id, 'periodo' => $apuracao->periodo, 'cargo' => $apuracao->cargo, 'nota' => $apuracao->nota],
        ], now(), $dono->id);

        $apuracao->update([
            'status' => BonusApuracao::CALCULADO,
            'justificativa_estorno' => $justificativa,
            'aprovado_por' => null,
            'aprovado_em' => null,
            'revisado_em' => null,
        ]);

        return $apuracao->refresh();
    }

    /**
     * Janela de aprovação: do dia 1 do mês seguinte ao 5º dia útil dele.
     */
    public function janelaDeAprovacao(string $periodo, ?Carbon $agora = null): bool
    {
        // A janela "dia 1 ao 5º dia útil" é de calendário e não pode ser dividida: no modo de teste fica aberta.
        if (ModoTeste::ativo()) {
            return true;
        }

        $agora ??= now();
        $inicio = Carbon::createFromFormat('Y-m', $periodo)->addMonth()->startOfMonth();
        $fim = BusinessHours::nthWeekday($inicio, 5);

        return $agora->between($inicio, $fim);
    }

    /**
     * Linhas do CSV da folha: só bônus aprovados.
     *
     * @return list<array<string, mixed>>
     */
    public function linhasDaFolha(string $periodo): array
    {
        return BonusApuracao::with('colaborador')
            ->where('periodo', $periodo)
            ->where('status', BonusApuracao::APROVADO)
            ->get()
            ->map(fn (BonusApuracao $a) => [
                'colaborador' => $a->colaborador?->nome,
                'cpf' => $a->colaborador?->cpf,
                'cargo' => $a->cargo,
                'nota' => $a->nota,
                'faixa' => $a->faixa_percentual,
                'trava' => $a->zerado_por_trava ? 'sim' : 'nao',
                'valor' => $a->valor,
            ])->sortBy('colaborador')->values()->all();
    }

    /**
     * @return array{user_id?: int, mechanic_id?: int}|array{}|null Null quando o cargo precisa de vínculo e não tem
     */
    protected function escopo(Colaborador $colaborador, string $cargo): ?array
    {
        return match ($cargo) {
            'consultor' => $colaborador->user_id ? ['user_id' => $colaborador->user_id] : null,
            'tecnico' => $colaborador->mechanic_id ? ['mechanic_id' => $colaborador->mechanic_id] : null,
            'zeladoria' => $colaborador->user_id ? ['user_id' => $colaborador->user_id] : [],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function avaliarMarcador(BonusMarcador $marcador, string $periodo, array $scope): array
    {
        $codigos = $marcador->codigosLista();
        $avaliacoes = collect($codigos)->map(fn ($codigo) => $this->indicators->evaluate($codigo, $periodo, $scope));

        $usaveis = $avaliacoes->filter(fn ($a) => in_array($a['situacao'], [IndicatorStatus::VERDE, IndicatorStatus::AMARELO, IndicatorStatus::VERMELHO], true));

        $fracao = null;

        if ($usaveis->isNotEmpty()) {
            // Marcador com mais de um código só pontua se todos baterem a meta.
            $fracao = count($codigos) > 1
                ? ($usaveis->count() === count($codigos) && $usaveis->every(fn ($a) => $a['situacao'] === IndicatorStatus::VERDE) ? 1.0 : 0.0)
                : $usaveis->first()['fracao'];
        }

        return [
            'marcador' => $marcador->nome ?: $avaliacoes->map(fn ($a) => $a['definicao']->nome)->implode(' + '),
            'codigos' => $codigos,
            'peso' => $marcador->peso,
            'resultado' => $marcador->resultado,
            'realizado' => $avaliacoes->map(fn ($a) => $a['resultado']->realizado)->all(),
            'metas' => $avaliacoes->map(fn ($a) => $a['meta'])->all(),
            'situacoes' => $avaliacoes->pluck('situacao')->all(),
            'fracao' => $fracao,
            'obs' => $usaveis->isEmpty() ? 'Sem dado no mês: marcador fora do cálculo.' : null,
        ];
    }

    /**
     * Gatilho de resultado da Ficha (Fase 2): faturamento do mês igual ou
     * acima do valor combinado. Não informado = não atingido.
     *
     * @return array{0: bool, 1: ?string}
     */
    protected function gatilho(string $periodo, int $fase): array
    {
        if ($fase === 1) {
            return [true, null];
        }

        $gatilho = WorkshopSettings::get('gatilho_resultado');

        if (! $gatilho) {
            return [false, 'Gatilho de resultado não informado na Ficha de Enquadramento: a parte de resultado não pontua.'];
        }

        [$from, $to] = $this->indicators->period($periodo);
        $faturamento = (float) ServiceOrder::query()->whereBetween('quotes.faturado_em', [$from, $to])->sum('grand_total');

        return $faturamento >= (float) $gatilho
            ? [true, null]
            : [false, 'Gatilho de resultado não atingido no mês: a parte de resultado não pontua.'];
    }

    protected function exigirNivel(User $user, int $minimo, string $mensagem): void
    {
        $perfis = array_map('trim', explode(',', (string) $user->role?->perfil_oficina));
        $nivel = in_array('proprietario', $perfis, true) ? 2 : (in_array('gerente', $perfis, true) ? 1 : 0);

        if ($nivel < $minimo) {
            throw ValidationException::withMessages(['bonus' => [$mensagem]]);
        }
    }
}
