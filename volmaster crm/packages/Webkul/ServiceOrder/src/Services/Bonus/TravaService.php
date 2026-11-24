<?php

namespace Webkul\ServiceOrder\Services\Bonus;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\BonusTrava;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\Lancamento;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\User\Models\User;

/**
 * Travas que zeram o bônus (capítulo 6.3). Duas origens:
 *
 * - automática: o sistema detecta bloqueios burlados (avanços forçados), peça
 *   fora do estoque sem OS, diferença de caixa sem justificativa e pendências
 *   de RH; a trava nasce "pendente";
 * - manual: quem constatou registra com evidência.
 *
 * Em qualquer caso o gerente confirma (ou rejeita com comentário); quando a
 * trava é do gerente, quem confirma é o dono.
 */
class TravaService
{
    public function __construct(protected BonusService $bonus) {}

    /**
     * Registro manual, com evidência obrigatória.
     */
    public function registrar(Colaborador $colaborador, string $cargo, string $tipo, string $descricao, string $evidencia, string $periodo, ?User $quem = null, ?int $osId = null): BonusTrava
    {
        if (! isset(BonusCatalog::travas()[$cargo][$tipo])) {
            throw ValidationException::withMessages(['tipo' => ['Escolha uma trava válida para o cargo.']]);
        }

        if (! $colaborador->temCargo($cargo)) {
            throw ValidationException::withMessages(['cargo' => ['O colaborador não ocupa esse cargo.']]);
        }

        if (trim($evidencia) === '') {
            throw ValidationException::withMessages(['evidencia' => ['A trava exige evidência (o que foi visto, onde e quando).']]);
        }

        return BonusTrava::create([
            'periodo' => $periodo,
            'colaborador_id' => $colaborador->id,
            'cargo' => $cargo,
            'tipo' => $tipo,
            'descricao' => mb_substr($descricao, 0, 500),
            'evidencia' => mb_substr($evidencia, 0, 500),
            'service_order_id' => $osId,
            'origem' => 'manual',
            'registrada_por' => $quem?->id ?? auth()->guard('user')->id(),
            'status' => BonusTrava::PENDENTE,
        ]);
    }

    /**
     * Confirma ou rejeita. O gerente decide as travas dos demais cargos; a
     * do gerente é decidida pelo dono.
     */
    public function decidir(BonusTrava $trava, User $user, bool $confirmar, ?string $comentario = null): BonusTrava
    {
        $nivel = $this->nivel($user);
        $exigido = $trava->cargo === 'gerente' ? 2 : 1;

        if ($nivel < $exigido) {
            throw ValidationException::withMessages(['trava' => [$trava->cargo === 'gerente' ? 'A trava do gerente é confirmada pelo dono.' : 'Só o gerente confirma travas.']]);
        }

        if ($trava->status !== BonusTrava::PENDENTE) {
            throw ValidationException::withMessages(['trava' => ['Esta trava já foi decidida.']]);
        }

        if (! $confirmar && trim((string) $comentario) === '') {
            throw ValidationException::withMessages(['comentario' => ['Explique por que a trava foi rejeitada.']]);
        }

        $trava->update([
            'status' => $confirmar ? BonusTrava::CONFIRMADA : BonusTrava::REJEITADA,
            'decidida_por' => $user->id,
            'decidida_em' => now(),
            'decisao_comentario' => $comentario,
        ]);

        // A nota do mês muda: recalcula a apuração se ainda não foi aprovada.
        $this->bonus->apurarColaborador($trava->colaborador, $trava->periodo);

        return $trava->refresh();
    }

    /**
     * Procura travas automáticas do mês e cria as que ainda não existem.
     *
     * @return int Quantidade de travas novas
     */
    public function detectar(string $periodo): int
    {
        $from = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        return $this->avancosForcados($periodo, $from, $to)
            + $this->diferencasDeCaixa($periodo, $from, $to)
            + $this->saidasDeEstoque($periodo, $from, $to)
            + $this->lancamentosSemCategoria($periodo, $from, $to)
            + $this->pendenciasDeRh($periodo, $to);
    }

    /**
     * DRE-08 vira trava do financeiro: pagamento sem conta no fechamento do mês.
     */
    protected function lancamentosSemCategoria(string $periodo, Carbon $from, Carbon $to): int
    {
        $quantidade = Lancamento::semConta()->whereBetween('competencia', [$from->toDateString(), $to->toDateString()])->count();

        if ($quantidade === 0) {
            return 0;
        }

        $novas = 0;

        foreach (Colaborador::ativos()->comCargo('financeiro')->get() as $financeiro) {
            $novas += $this->criarAutomatica($periodo, $financeiro, 'financeiro', 'lancamento_sem_categoria', "semconta:{$periodo}:{$financeiro->id}", "{$quantidade} lançamento(s) de {$periodo} ficaram sem conta no plano de contas.");
        }

        return $novas;
    }

    /**
     * Bloqueio burlado: quem forçou o avanço responde pela trava do cargo que
     * a etapa envolve.
     */
    protected function avancosForcados(string $periodo, Carbon $from, Carbon $to): int
    {
        $novas = 0;

        $eventos = ServiceOrderEvent::query()->where('tipo_evento', 'bloqueio_forcado')->whereBetween('ocorrido_em', [$from, $to])->get();

        foreach ($eventos as $evento) {
            $colaborador = $evento->usuario_id ? Colaborador::where('user_id', $evento->usuario_id)->first() : null;

            if (! $colaborador) {
                continue;
            }

            $faltava = strtolower(implode(' ', (array) ($evento->dados_json['faltava'] ?? [])));
            $destino = $evento->etapa_destino;
            $origem = $evento->etapa_origem;

            $candidatas = match (true) {
                $destino === 'Em Execução' => [['consultor', 'servico_sem_aprovacao'], ['chefe', 'execucao_sem_autorizacao']],
                $destino === 'Pós-Venda' => [['consultor', 'liberado_sem_quitacao'], ['financeiro', 'saida_sem_nf']],
                $origem === 'Veículo em Teste' && (str_contains($faltava, 'cnh') || str_contains($faltava, 'autoriza')) => [['chefe', 'teste_sem_autorizacao']],
                $origem === 'Veículo em Teste' => [['chefe', 'entrega_sem_teste']],
                $destino === 'Veículo em Teste' && str_contains($faltava, 'montagem') => [['chefe', 'montagem_sem_checklist']],
                default => [],
            };

            foreach ($candidatas as [$cargo, $tipo]) {
                if ($colaborador->temCargo($cargo)) {
                    $novas += $this->criarAutomatica($periodo, $colaborador, $cargo, $tipo, "forcado:{$evento->id}:{$cargo}", "Avanço forçado de {$origem} para {$destino} na OS #{$evento->service_order_id}: ".($evento->dados_json['justificativa'] ?? ''), $evento->service_order_id);
                }
            }
        }

        return $novas;
    }

    protected function diferencasDeCaixa(string $periodo, Carbon $from, Carbon $to): int
    {
        $novas = 0;

        $registros = MentoriaRegistro::vigentes()->doTipo('caixa_dia')->noPeriodo($from, $to)->get()
            ->filter(fn ($r) => abs((float) $r->valor) >= 0.005 && ! $r->ok);

        foreach ($registros as $registro) {
            foreach (Colaborador::ativos()->comCargo('financeiro')->get() as $financeiro) {
                $novas += $this->criarAutomatica($periodo, $financeiro, 'financeiro', 'caixa_sem_justificativa', "caixa:{$registro->id}:{$financeiro->id}", 'Diferença de caixa sem justificativa em '.$registro->ocorrido_em->format('d/m/Y').'.');
            }
        }

        return $novas;
    }

    /**
     * Peça saída do estoque sem estar lançada numa OS.
     */
    protected function saidasDeEstoque(string $periodo, Carbon $from, Carbon $to): int
    {
        $novas = 0;

        $saidas = DB::table('part_stock_movements')
            ->where('type', 'saida')
            ->whereNull('reference_id')
            ->whereBetween('created_at', [$from, $to])
            ->get();

        foreach ($saidas as $saida) {
            foreach (Colaborador::ativos()->comCargo('pecas')->get() as $pecas) {
                $novas += $this->criarAutomatica($periodo, $pecas, 'pecas', 'saida_sem_lancamento', "estoque:{$saida->id}:{$pecas->id}", 'Saída de estoque sem OS (movimento #'.$saida->id.', '.Carbon::parse($saida->created_at)->format('d/m/Y').').');
            }
        }

        return $novas;
    }

    /**
     * Gerente: colaborador admitido sem registro antes do início e férias vencidas.
     */
    protected function pendenciasDeRh(string $periodo, Carbon $fim): int
    {
        $novas = 0;
        $gerentes = Colaborador::ativos()->comCargo('gerente')->get();

        foreach ($gerentes as $gerente) {
            foreach (Colaborador::ativos()->get() as $colaborador) {
                if ($colaborador->feriasVencidas() && $colaborador->ferias_vencimento->lte($fim)) {
                    $novas += $this->criarAutomatica($periodo, $gerente, 'gerente', 'ferias_vencidas', "ferias:{$colaborador->id}:{$periodo}:{$gerente->id}", "Férias vencidas de {$colaborador->nome} (venceram em ".$colaborador->ferias_vencimento->format('d/m/Y').').');
                }

                if ($colaborador->registro_antes_inicio === false && $colaborador->admissao && $colaborador->admissao->format('Y-m') === $periodo) {
                    $novas += $this->criarAutomatica($periodo, $gerente, 'gerente', 'colaborador_sem_registro', "admissao:{$colaborador->id}:{$gerente->id}", "{$colaborador->nome} começou sem o registro feito antes do início.");
                }
            }
        }

        return $novas;
    }

    protected function criarAutomatica(string $periodo, Colaborador $colaborador, string $cargo, string $tipo, string $chave, string $descricao, ?int $osId = null): int
    {
        if (BonusTrava::where('chave_unica', $chave)->exists()) {
            return 0;
        }

        BonusTrava::create([
            'periodo' => $periodo,
            'colaborador_id' => $colaborador->id,
            'cargo' => $cargo,
            'tipo' => $tipo,
            'descricao' => mb_substr($descricao, 0, 500),
            'service_order_id' => $osId,
            'origem' => 'automatica',
            'chave_unica' => $chave,
            'status' => BonusTrava::PENDENTE,
        ]);

        return 1;
    }

    protected function nivel(User $user): int
    {
        $perfis = array_map('trim', explode(',', (string) $user->role?->perfil_oficina));

        return in_array('proprietario', $perfis, true) ? 2 : (in_array('gerente', $perfis, true) ? 1 : 0);
    }
}
