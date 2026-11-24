<?php

namespace Webkul\ServiceOrder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Webkul\ServiceOrder\Services\BarrierEngine;
use Webkul\User\Models\User;

/**
 * Registro de evento da OS. Append-only: nunca se edita nem se apaga um
 * evento; uma correção é um novo evento (tipo "correcao") com justificativa.
 */
class ServiceOrderEvent extends Model
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    /**
     * Tipos de evento aceitos, com o rótulo mostrado na linha do tempo.
     */
    public const TIPOS = [
        'mudanca_etapa' => 'Mudança de etapa',
        'chegada_veiculo' => 'Chegada do veículo',
        'checklist_entrada' => 'Checklist de entrada registrado',
        'termo_assinado' => 'Termo de entrada assinado',
        'cor_credito_registrada' => 'Cor de crédito registrada',
        'tecnico_designado' => 'Técnico designado',
        'diagnostico_inicio' => 'Diagnóstico iniciado',
        'diagnostico_fim' => 'Diagnóstico finalizado',
        'diagnostico_auditado' => 'Diagnóstico auditado pelo chefe',
        'laudo_evidencia' => 'Evidência adicionada ao laudo',
        'diagnostico_midia' => 'Fotos e vídeos do diagnóstico enviados',
        'extra_adicionado' => 'Anotação ou anexo adicionado à OS',
        'causa_atraso_registrada' => 'Causa do atraso no diagnóstico',
        'relacao_pecas_entregue' => 'Relação de peças entregue',
        'orcamento_enviado' => 'Orçamento enviado ao cliente',
        'cliente_aprovou' => 'Cliente aprovou',
        'cliente_recusou' => 'Cliente recusou',
        'forma_pagamento_registrada' => 'Forma de pagamento registrada',
        'execucao_liberada' => 'Execução liberada',
        'operacao_inicio' => 'Operação iniciada',
        'operacao_pausa' => 'Operação pausada',
        'operacao_retomada' => 'Operação retomada',
        'operacao_fim' => 'Operação concluída',
        'operacao_atraso_confirmado' => 'Atraso da operação confirmado pelo chefe',
        'teste_inicio' => 'Test drive iniciado',
        'teste_fim' => 'Test drive finalizado',
        'nf_emitida' => 'Nota fiscal emitida',
        'pagamento_confirmado' => 'Pagamento confirmado',
        'acordo_assinado' => 'Acordo de pagamento assinado',
        'saida_veiculo' => 'Saída do veículo',
        'posvenda_contato' => 'Contato de pós-venda',

        // Entrega 2 (capítulo 11): passos que alimentam as demais barreiras.
        'recepcao' => 'Cliente recepcionado',
        'ficha_enviada_chefe' => 'Ficha enviada ao chefe',
        'ficha_devolvida' => 'Ficha devolvida pelo chefe',
        'peca_precificada' => 'Peça precificada',
        'os_completa_consultor' => 'OS completa enviada ao consultor',
        'orcamento_corrigido' => 'Orçamento corrigido por peças ou consultor',
        'cliente_aprovou_parcial' => 'Cliente aprovou parcialmente',
        'desconto_aplicado' => 'Desconto aplicado',
        'compra_peca' => 'Peça comprada',
        'peca_entregue_tecnico' => 'Peça entregue ao técnico',
        'cliente_atualizado' => 'Cliente atualizado sobre o serviço',
        'item_adicional' => 'Item adicional identificado',
        'termo_pecas_velhas' => 'Termo de peças velhas assinado',
        'checklist_montagem' => 'Checklist de montagem concluído',
        'prazo_informado' => 'Prazo informado ao cliente',
        'conferencia_final' => 'Conferência final do chefe',
        'pecas_conferidas' => 'Peças aplicadas conferidas com as lançadas',
        'checklist_saida' => 'Checklist de saída',
        'os_enviada_financeiro' => 'OS enviada ao financeiro',
        'boleto_ou_cobranca_gerada' => 'Boleto ou cobrança gerada',
        'nota_cliente' => 'Nota de recomendação do cliente',
        'reclamacao_aberta' => 'Reclamação aberta',
        'reclamacao_fechada' => 'Reclamação fechada',
        'retorno_aberto' => 'OS de retorno aberta',
        'retorno_classificado' => 'Causa do retorno classificada',
        'chamado_autorizado' => 'Chamado externo autorizado',
        'pre_diagnostico_remoto' => 'Pré-diagnóstico remoto',
        'caixa_diferenca' => 'Diferença no fechamento do caixa',
        'alcada_pedida' => 'Pedido de alçada aberto',
        'alcada_encaminhada' => 'Pedido de alçada encaminhado ao dono',
        'alcada_aprovada' => 'Pedido de alçada aprovado',
        'alcada_recusada' => 'Pedido de alçada recusado',
        'recontato_recusa' => 'Recontato após recusa do orçamento',
        'acao_dono_fora_fluxo' => 'Ação do dono fora do fluxo',
        'bloqueio_forcado' => 'Avanço forçado com justificativa',
        'alerta_enviado' => 'Alerta enviado',
        'alerta_resolvido' => 'Alerta resolvido',
        'correcao' => 'Correção de registro',
    ];

    protected $table = 'service_order_events';

    protected $guarded = [];

    protected $casts = [
        'ocorrido_em' => 'datetime',
        'criado_em' => 'datetime',
        'dados_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Eventos da OS não podem ser alterados; registre uma correção.');
        });

        static::deleting(function () {
            throw new LogicException('Eventos da OS não podem ser apagados; registre uma correção.');
        });

        static::created(function (self $event) {
            // O motor de barreiras reage ao evento, mas uma falha nele nunca
            // pode impedir o trabalho da oficina.
            try {
                app(BarrierEngine::class)->handleEvent($event);
            } catch (\Throwable $exception) {
                Log::error('Falha no motor de barreiras ao processar evento #'.$event->id.': '.$exception->getMessage());
            }
        });
    }

    /**
     * Grava um evento. O usuário é o logado (ou null para ações do sistema).
     */
    public static function record(
        ServiceOrder|int $serviceOrder,
        string $tipo,
        array $dados = [],
        ?string $etapaOrigem = null,
        ?string $etapaDestino = null,
        ?Carbon $ocorridoEm = null,
        ?int $usuarioId = null,
    ): self {
        if (! array_key_exists($tipo, self::TIPOS)) {
            throw new InvalidArgumentException("Tipo de evento desconhecido: {$tipo}");
        }

        return static::create([
            'oficina_id' => app()->bound('currentTenant') ? app('currentTenant')->id : null,
            'service_order_id' => $serviceOrder instanceof ServiceOrder ? $serviceOrder->id : $serviceOrder,
            'tipo_evento' => $tipo,
            'etapa_origem' => $etapaOrigem,
            'etapa_destino' => $etapaDestino,
            // 0 = ação do sistema (sem usuário), ex.: disparo de alerta.
            'usuario_id' => $usuarioId === 0 ? null : ($usuarioId ?? auth()->guard('user')->id()),
            'ocorrido_em' => $ocorridoEm ?? now(),
            'dados_json' => $dados ?: null,
        ]);
    }

    /**
     * Correção: novo evento apontando para o original, com justificativa.
     */
    public static function correct(self $original, string $justificativa, array $dados = []): self
    {
        if (trim($justificativa) === '') {
            throw new InvalidArgumentException('A correção exige justificativa.');
        }

        return static::record($original->service_order_id, 'correcao', array_merge($dados, [
            'corrige_evento_id' => $original->id,
            'corrige_tipo' => $original->tipo_evento,
            'justificativa' => $justificativa,
        ]));
    }

    public function getRotuloAttribute(): string
    {
        return self::TIPOS[$this->tipo_evento] ?? $this->tipo_evento;
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
