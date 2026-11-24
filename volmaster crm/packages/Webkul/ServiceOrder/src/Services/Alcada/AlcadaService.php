<?php

namespace Webkul\ServiceOrder\Services\Alcada;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\AlcadaRegra;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Services\AlertService;
use Webkul\ServiceOrder\Services\ModoTeste;
use Webkul\ServiceOrder\Services\StageGate;
use Webkul\User\Models\User;

/**
 * Matriz de alçadas (capítulo 7): toda ação acima da alçada de quem a pede
 * fica bloqueada até a aprovação de quem tem a alçada, com registro.
 *
 * Fluxo: o pedido nasce na fila do gerente; o gerente aprova (dentro da
 * alçada dele), recusa ou encaminha ao dono. O dono só enxerga o que o
 * gerente encaminhou -- e tem 24 h para responder (DON-06).
 *
 * Quem pediu repete a ação depois da aprovação: a aprovação é "consumida"
 * uma única vez (nada passa duas vezes com a mesma autorização).
 */
class AlcadaService
{
    public const PRAZO_DONO_HORAS = 24;

    public function __construct(protected AlertService $alerts) {}

    /**
     * Nível do usuário na hierarquia: 0 = base, 1 = gerente, 2 = proprietário.
     */
    public function levelOf(?User $user): int
    {
        if (! $user) {
            return AlcadaCatalog::NIVEL_NENHUM;
        }

        $perfis = array_map('trim', explode(',', (string) $user->role?->perfil_oficina));

        return match (true) {
            in_array('proprietario', $perfis, true) => AlcadaCatalog::NIVEL_DONO,
            in_array('gerente', $perfis, true) => AlcadaCatalog::NIVEL_GERENTE,
            default => AlcadaCatalog::NIVEL_NENHUM,
        };
    }

    /**
     * Que nível precisa aprovar este valor neste tema?
     *
     * @return array{nivel: int, configurada: bool}
     */
    public function required(string $tema, ?float $valor): array
    {
        $definicao = AlcadaCatalog::tema($tema);

        if (! $definicao) {
            return ['nivel' => AlcadaCatalog::NIVEL_NENHUM, 'configurada' => false];
        }

        AlcadaRegra::ensureDefaultsOnce();
        $regra = AlcadaRegra::find($tema);

        if (! $regra || ! $regra->ativo) {
            return ['nivel' => AlcadaCatalog::NIVEL_NENHUM, 'configurada' => true];
        }

        return match ($definicao['tipo']) {
            'gerente' => ['nivel' => AlcadaCatalog::NIVEL_GERENTE, 'configurada' => true],
            'dono' => ['nivel' => AlcadaCatalog::NIVEL_DONO, 'configurada' => true],
            'cor' => ['nivel' => (int) max(0, min(2, (int) $valor)), 'configurada' => true],
            default => $this->limites($definicao, $regra, (float) $valor),
        };
    }

    /**
     * @return array{nivel: int, configurada: bool}
     */
    protected function limites(array $definicao, AlcadaRegra $regra, float $valor): array
    {
        $sozinho = $regra->limite_sozinho;
        $gerente = $regra->limite_gerente;

        // Sem o limite preenchido pelo dono não há como julgar "acima da alçada".
        $configurada = $definicao['sozinho_aplica'] ? $sozinho !== null : $gerente !== null;

        if (! $configurada) {
            return ['nivel' => AlcadaCatalog::NIVEL_NENHUM, 'configurada' => false];
        }

        if ($definicao['sozinho_aplica'] && $valor <= $sozinho) {
            return ['nivel' => AlcadaCatalog::NIVEL_NENHUM, 'configurada' => true];
        }

        if ($definicao['gerente_aprova'] && ($gerente === null || $valor <= $gerente)) {
            return ['nivel' => AlcadaCatalog::NIVEL_GERENTE, 'configurada' => true];
        }

        return ['nivel' => AlcadaCatalog::NIVEL_DONO, 'configurada' => true];
    }

    /**
     * Confere a alçada de uma ação automática. Passa em silêncio quando o
     * valor cabe na alçada de quem pede, quando há aprovação a consumir ou
     * quando as alçadas não estão em uso; senão cria o pedido e bloqueia.
     *
     * @throws ValidationException
     */
    public function authorize(string $tema, ?ServiceOrder $os, ?float $valor, string $descricao, ?User $user = null): void
    {
        $user ??= auth()->guard('user')->user();

        if (! StageGate::active() || ! $user) {
            return;
        }

        ['nivel' => $necessario] = $this->required($tema, $valor);

        if ($necessario === AlcadaCatalog::NIVEL_NENHUM || $this->levelOf($user) >= $necessario) {
            return;
        }

        if ($this->consumeApproval($tema, $os, $valor)) {
            return;
        }

        $aberto = AlcadaPedido::abertos()->where('tema', $tema)->where('service_order_id', $os?->id)->first();

        if ($aberto) {
            throw ValidationException::withMessages(['alcada' => [
                'Já existe um pedido de "'.$aberto->tema_nome.'" aguardando '.($aberto->status === AlcadaPedido::ENCAMINHADO ? 'o dono' : 'o gerente').'.',
            ]]);
        }

        $pedido = $this->open($tema, $os, $valor, $descricao, $user, $necessario);

        throw ValidationException::withMessages(['alcada' => [
            'Esta ação passa da sua alçada ('.$pedido->tema_nome.'). O pedido foi enviado para aprovação '
            .($pedido->status === AlcadaPedido::ENCAMINHADO ? 'do dono' : 'do gerente').'; repita a ação depois da aprovação.',
        ]]);
    }

    /**
     * Pedido manual de decisão (contratação, investimento, mudança de
     * processo...). Não bloqueia nada: registra a decisão com data e autor.
     */
    public function request(string $tema, string $descricao, ?float $valor, ?ServiceOrder $os, ?User $user = null): AlcadaPedido
    {
        $user ??= auth()->guard('user')->user();

        ['nivel' => $necessario] = $this->required($tema, $valor);

        // Sem regra configurada, o gerente é o primeiro a ver.
        $necessario = max($necessario, AlcadaCatalog::NIVEL_GERENTE);

        return $this->open($tema, $os, $valor, $descricao, $user, $necessario);
    }

    protected function open(string $tema, ?ServiceOrder $os, ?float $valor, string $descricao, ?User $user, int $necessario): AlcadaPedido
    {
        $nivelUsuario = $this->levelOf($user);

        // Pedido que o próprio gerente faz acima da alçada dele já nasce no dono.
        // Pedido que o próprio gerente faz acima da alçada dele já nasce no dono; prazo para cliente
        // Vermelho (B1) também vai DIRETO ao Proprietário, com 24 h (o gerente vê o pedido na fila).
        $direto = ($necessario === AlcadaCatalog::NIVEL_DONO && $nivelUsuario >= AlcadaCatalog::NIVEL_GERENTE)
            || ($tema === 'condicao_pagamento' && (float) $valor >= 2);

        $pedido = AlcadaPedido::create([
            'tema' => $tema,
            'service_order_id' => $os?->id,
            'solicitante_id' => $user?->id,
            'valor' => $valor,
            'descricao' => mb_substr($descricao, 0, 500),
            'nivel_necessario' => $necessario === AlcadaCatalog::NIVEL_DONO ? 'proprietario' : 'gerente',
            'status' => $direto ? AlcadaPedido::ENCAMINHADO : AlcadaPedido::PENDENTE,
            'encaminhado_por' => $direto ? $user?->id : null,
            'encaminhado_em' => $direto ? now() : null,
            'prazo_dono_em' => $direto ? ModoTeste::depois(now(), self::PRAZO_DONO_HORAS * 60) : null,
        ]);

        if ($os) {
            ServiceOrderEvent::record($os, 'alcada_pedida', ['pedido_id' => $pedido->id, 'tema' => $tema]);
        }

        $direto ? $this->notifyOwner($pedido, $os) : $this->notifyManager($pedido, $os);

        return $pedido;
    }

    /**
     * O gerente (ou o dono) leva o pedido ao dono. Mantém o prazo de 24 h.
     */
    public function forward(AlcadaPedido $pedido, User $user, ?string $comentario = null): AlcadaPedido
    {
        if ($this->levelOf($user) < AlcadaCatalog::NIVEL_GERENTE) {
            throw ValidationException::withMessages(['alcada' => ['Só o gerente encaminha pedidos ao dono.']]);
        }

        if ($pedido->status !== AlcadaPedido::PENDENTE) {
            throw ValidationException::withMessages(['alcada' => ['Este pedido não está mais aguardando o gerente.']]);
        }

        $pedido->update([
            'status' => AlcadaPedido::ENCAMINHADO,
            'nivel_necessario' => 'proprietario',
            'encaminhado_por' => $user->id,
            'encaminhado_em' => now(),
            'prazo_dono_em' => ModoTeste::depois(now(), self::PRAZO_DONO_HORAS * 60),
            'comentario' => $comentario ?: $pedido->comentario,
        ]);

        $os = $pedido->service_order_id ? ServiceOrder::find($pedido->service_order_id) : null;

        if ($os) {
            ServiceOrderEvent::record($os, 'alcada_encaminhada', ['pedido_id' => $pedido->id, 'tema' => $pedido->tema]);
        }

        $this->notifyOwner($pedido, $os);

        return $pedido;
    }

    /**
     * Aprova ou recusa. O gerente decide o que está na alçada dele; o dono,
     * qualquer pedido -- mas decidir algo que o gerente não encaminhou conta
     * como "dono acionado fora da alçada" (DON-05).
     */
    public function decide(AlcadaPedido $pedido, User $user, bool $aprovar, ?string $comentario = null): AlcadaPedido
    {
        $nivel = $this->levelOf($user);
        $exigido = $pedido->nivel_necessario === 'proprietario' ? AlcadaCatalog::NIVEL_DONO : AlcadaCatalog::NIVEL_GERENTE;

        if ($nivel < $exigido) {
            throw ValidationException::withMessages(['alcada' => ['Este pedido passa da sua alçada: encaminhe ao dono.']]);
        }

        if (! in_array($pedido->status, [AlcadaPedido::PENDENTE, AlcadaPedido::ENCAMINHADO], true)) {
            throw ValidationException::withMessages(['alcada' => ['Este pedido já foi decidido.']]);
        }

        if (! $aprovar && trim((string) $comentario) === '') {
            throw ValidationException::withMessages(['comentario' => ['Explique o motivo da recusa.']]);
        }

        $foraDaAlcada = $nivel === AlcadaCatalog::NIVEL_DONO && $pedido->status === AlcadaPedido::PENDENTE;

        $pedido->update([
            'status' => $aprovar ? AlcadaPedido::APROVADO : AlcadaPedido::RECUSADO,
            'decidido_por' => $user->id,
            'decidido_em' => now(),
            'comentario' => $comentario,
            'sem_gerente' => $foraDaAlcada,
        ]);

        $os = $pedido->service_order_id ? ServiceOrder::find($pedido->service_order_id) : null;

        if ($os) {
            ServiceOrderEvent::record($os, $aprovar ? 'alcada_aprovada' : 'alcada_recusada', ['pedido_id' => $pedido->id, 'tema' => $pedido->tema]);
        }

        if ($foraDaAlcada && $os) {
            ServiceOrderEvent::record($os, 'acao_dono_fora_fluxo', ['motivo' => 'Decidiu pedido sem passar pelo gerente', 'pedido_id' => $pedido->id]);
        }

        if ($pedido->solicitante_id && ($solicitante = User::find($pedido->solicitante_id))) {
            $this->alerts->notifyUser(
                $solicitante,
                'alcada_decisao',
                'Pedido de alçada '.($aprovar ? 'aprovado' : 'recusado'),
                'Pedido de "'.$pedido->tema_nome.'"'.($pedido->service_order_id ? ' da OS #'.$pedido->service_order_id : '').' '.($aprovar ? 'aprovado. Repita a ação.' : 'recusado: '.$comentario),
                $os,
            );
        }

        return $pedido;
    }

    /**
     * Fila do usuário: o gerente vê os pendentes; o dono, só o que foi encaminhado.
     *
     * @return Collection<int, AlcadaPedido>
     */
    public function queueFor(User $user): Collection
    {
        $nivel = $this->levelOf($user);

        if ($nivel === AlcadaCatalog::NIVEL_NENHUM) {
            return collect();
        }

        return AlcadaPedido::query()
            ->with('solicitante:id,name')
            ->when($nivel === AlcadaCatalog::NIVEL_DONO, fn ($q) => $q->where('status', AlcadaPedido::ENCAMINHADO))
            ->when($nivel === AlcadaCatalog::NIVEL_GERENTE, fn ($q) => $q->abertos())
            ->orderBy('id')
            ->get();
    }

    /**
     * Aprovação ainda não aplicada que cobre a ação pedida.
     */
    protected function consumeApproval(string $tema, ?ServiceOrder $os, ?float $valor): bool
    {
        $pedido = AlcadaPedido::query()
            ->where('tema', $tema)
            ->where('service_order_id', $os?->id)
            ->where('status', AlcadaPedido::APROVADO)
            ->where(fn ($q) => $q->whereNull('valor')->orWhere('valor', '>=', (float) $valor))
            ->orderBy('id')
            ->first();

        if (! $pedido) {
            return false;
        }

        $pedido->update(['status' => AlcadaPedido::CONSUMIDO, 'consumido_em' => now()]);

        return true;
    }

    protected function notifyManager(AlcadaPedido $pedido, ?ServiceOrder $os): void
    {
        $this->alerts->raise(
            'alcada_pedido',
            'Pedido de alçada',
            'Pedido de "'.$pedido->tema_nome.'"'.($os ? ' na OS #'.$os->id : '').' aguardando sua decisão.',
            ['gerente'],
            $os,
        );
    }

    /**
     * Pedido que exige o dono: um dos poucos avisos que chegam direto a ele.
     */
    protected function notifyOwner(AlcadaPedido $pedido, ?ServiceOrder $os): void
    {
        $this->alerts->raise(
            'alcada_dono',
            'Pedido de alçada do dono',
            'Pedido de "'.$pedido->tema_nome.'"'.($os ? ' na OS #'.$os->id : '').' aguardando sua decisão em até '.ModoTeste::duracao(ModoTeste::minutos(self::PRAZO_DONO_HORAS * 60)).'.',
            ['proprietario'],
            $os,
            critico: true,
        );
    }
}
