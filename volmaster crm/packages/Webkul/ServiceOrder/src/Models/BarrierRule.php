<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Regra de barreira de prazo (configurável por oficina). Os valores padrão
 * da mentoria ficam em DEFAULTS e são semeados na primeira necessidade.
 *
 * Modos:
 * - minutos:   prazo fixo (prazo_minutos) após o evento de início;
 * - dinamico:  prazo vem do próprio evento (prazo_campo), ex.: tempo alvo da queixa;
 * - fim_do_dia: vence no fechamento do expediente do dia do evento;
 * - data_alvo: vence "prazo_minutos" antes da data informada no evento (prazo_em);
 * - operacao:  tempo trabalhado (sem pausas) acima de X% do tempo padrão;
 * - imediato:  alerta crítico na hora, sem prazo.
 */
class BarrierRule extends Model
{
    public const MODO_MINUTOS = 'minutos';

    public const MODO_DINAMICO = 'dinamico';

    public const MODO_FIM_DO_DIA = 'fim_do_dia';

    public const MODO_DATA_ALVO = 'data_alvo';

    public const MODO_OPERACAO = 'operacao';

    /** Dispara na hora em que o evento de início é gravado (alertas críticos). */
    public const MODO_IMEDIATO = 'imediato';

    public const DESTINOS = [
        'tecnico_da_os' => 'Técnico da OS',
        'chefe' => 'Chefe de oficina',
        'consultor' => 'Consultor técnico',
        'pecas' => 'Peças',
        'financeiro' => 'Financeiro',
        'gerente' => 'Gerente',
        'proprietario' => 'Proprietário',
    ];

    /**
     * Barreiras padrão da mentoria (capítulo 11.2). A oficina ajusta prazos e
     * destinos na configuração.
     *
     * Observação sobre o dono: a regra do dono blindado diz que ele só recebe
     * o nível 3 e alertas críticos. A "Reclamação" (dono aos 48 h) fica por
     * isso no nível 3, com 12 h depois do alerta ao gerente (36 h).
     */
    public const DEFAULTS = [
        [
            'codigo' => 'recepcao',
            'nome' => 'Recepção do cliente',
            'etapa' => 'Cadastro',
            'evento_inicio' => 'chegada_veiculo',
            'evento_fim' => 'recepcao',
            'modo' => self::MODO_MINUTOS,
            'prazo_minutos' => 10,
            'nivel1_destino' => 'consultor',
            'nivel2_apos_minutos' => 10,
            'nivel2_destino' => 'gerente',
            'critico' => false,
        ],
        [
            'codigo' => 'cor_credito',
            'nome' => 'Cor de crédito',
            'etapa' => 'Cadastro',
            'evento_inicio' => 'chegada_veiculo',
            'evento_fim' => 'cor_credito_registrada',
            'modo' => self::MODO_MINUTOS,
            'prazo_minutos' => 30,
            'nivel1_destino' => 'financeiro',
            'nivel2_apos_minutos' => 15,
            'nivel2_destino' => 'gerente',
            'critico' => false,
        ],
        [
            'codigo' => 'tecnico_designado',
            'nome' => 'Técnico designado',
            'etapa' => 'Ordem de Serviço',
            'evento_inicio' => 'ficha_enviada_chefe',
            // Devolver a ficha também encerra o prazo do chefe (reenviar reabre).
            'evento_fim' => 'tecnico_designado,ficha_devolvida',
            'modo' => self::MODO_MINUTOS,
            'prazo_minutos' => 30,
            'nivel1_destino' => 'chefe',
            'nivel2_apos_minutos' => 15,
            'nivel2_destino' => 'gerente',
            'critico' => false,
        ],
        [
            'codigo' => 'diagnostico_tempo',
            'nome' => 'Diagnóstico no tempo',
            'etapa' => 'Diagnóstico',
            'evento_inicio' => 'diagnostico_inicio',
            'evento_fim' => 'diagnostico_fim',
            'modo' => self::MODO_DINAMICO,
            'prazo_campo' => 'tempo_alvo_min',
            'nivel1_destino' => 'tecnico_da_os,chefe',
            'nivel2_apos_percentual' => 20,
            'nivel2_destino' => 'gerente',
            'critico' => false,
        ],
        [
            'codigo' => 'relacao_pecas',
            'nome' => 'Relação de peças',
            'etapa' => 'Diagnóstico',
            'evento_inicio' => 'diagnostico_fim',
            'evento_fim' => 'relacao_pecas_entregue',
            'modo' => self::MODO_MINUTOS,
            'prazo_minutos' => 30,
            'nivel1_destino' => 'tecnico_da_os,chefe',
            'nivel2_apos_minutos' => 30,
            'nivel2_destino' => 'gerente',
            'critico' => false,
        ],
        [
            'codigo' => 'precificacao_peca',
            'nome' => 'Precificação de peça',
            'etapa' => 'Orçamento',
            'evento_inicio' => 'relacao_pecas_entregue',
            'evento_fim' => 'peca_precificada',
            'modo' => self::MODO_DINAMICO,
            // 20 min se todas as peças estão em estoque, 40 min se há fornecedor.
            'prazo_campo' => 'prazo_precificacao_min',
            'prazo_minutos' => 40,
            'nivel1_destino' => 'pecas',
            'nivel2_apos_percentual' => 50,
            'nivel2_destino' => 'chefe',
            'condicao_inicio' => 'tem_pecas',
            'condicao_fim' => 'todas_precificadas',
            'critico' => false,
        ],
        [
            'codigo' => 'os_completa_consultor',
            'nome' => 'OS completa ao consultor',
            'etapa' => 'Diagnóstico',
            'evento_inicio' => 'diagnostico_fim',
            'evento_fim' => 'os_completa_consultor',
            'modo' => self::MODO_MINUTOS,
            'prazo_minutos' => 60,
            'nivel1_destino' => 'chefe',
            'nivel2_apos_minutos' => 30,
            'nivel2_destino' => 'gerente',
            'critico' => false,
        ],
        [
            'codigo' => 'orcamento_2h',
            'nome' => 'Orçamento em 2 horas',
            'etapa' => 'Diagnóstico',
            'evento_inicio' => 'diagnostico_fim',
            'evento_fim' => 'orcamento_enviado',
            'modo' => self::MODO_MINUTOS,
            'prazo_minutos' => 120,
            'nivel1_destino' => 'consultor,chefe',
            'nivel2_apos_minutos' => 60,
            'nivel2_destino' => 'gerente',
            'nivel3_apos_minutos' => 120,
            'nivel3_destino' => 'proprietario',
            'critico' => false,
        ],
        [
            'codigo' => 'resposta_cliente',
            'nome' => 'Resposta do cliente',
            'etapa' => 'Negociação',
            'evento_inicio' => 'orcamento_enviado',
            'evento_fim' => 'cliente_aprovou,cliente_aprovou_parcial,cliente_recusou',
            'modo' => self::MODO_MINUTOS,
            'prazo_minutos' => 2880,
            'nivel1_destino' => 'consultor',
            'nivel2_apos_minutos' => 1440,
            'nivel2_destino' => 'gerente',
            'conta_horario_comercial' => false,
            'critico' => false,
        ],
        [
            'codigo' => 'compra_peca',
            'nome' => 'Compra da peça',
            'etapa' => 'Negociação',
            'evento_inicio' => 'cliente_aprovou,cliente_aprovou_parcial',
            'evento_fim' => 'compra_peca',
            'modo' => self::MODO_FIM_DO_DIA,
            'nivel1_destino' => 'pecas',
            // 1 dia útil depois do fim do dia.
            'nivel2_apos_minutos' => 1,
            'nivel2_destino' => 'gerente',
            'unidade_offset' => 'dias',
            'condicao_inicio' => 'precisa_compra',
            'condicao_fim' => 'todas_compradas',
            'critico' => false,
        ],
        [
            'codigo' => 'atualizacao_cliente',
            'nome' => 'Atualização ao cliente',
            'etapa' => 'Em Execução',
            'evento_inicio' => 'execucao_liberada,cliente_atualizado',
            'evento_fim' => 'cliente_atualizado,teste_inicio,saida_veiculo',
            'modo' => self::MODO_MINUTOS,
            'prazo_minutos' => 1440,
            'nivel1_destino' => 'consultor',
            'nivel2_apos_minutos' => 1440,
            'nivel2_destino' => 'gerente',
            'conta_horario_comercial' => false,
            'critico' => false,
        ],
        [
            'codigo' => 'operacao_tempo',
            'nome' => 'Operação acima do tempo',
            'etapa' => 'Em Execução',
            'evento_inicio' => 'operacao_inicio',
            'evento_fim' => 'operacao_fim',
            'modo' => self::MODO_OPERACAO,
            'nivel1_percentual' => 120,
            'nivel1_destino' => 'chefe',
            'nivel2_percentual' => 150,
            'nivel2_destino' => 'gerente',
            'critico' => false,
        ],
        [
            'codigo' => 'prazo_prometido',
            'nome' => 'Prazo prometido em risco',
            'etapa' => 'Em Execução',
            'evento_inicio' => 'prazo_informado',
            'evento_fim' => 'prazo_informado,teste_inicio,saida_veiculo',
            'modo' => self::MODO_DATA_ALVO,
            // Avisa 24 h antes do prazo prometido, enquanto a OS não chegou ao teste.
            'prazo_minutos' => 1440,
            'nivel1_destino' => 'chefe,consultor',
            'nivel2_apos_minutos' => 1440,
            'nivel2_destino' => 'gerente',
            'nivel3_apos_minutos' => 2880,
            'nivel3_destino' => 'proprietario',
            'conta_horario_comercial' => false,
            'condicao_inicio' => 'antes_do_teste',
            'critico' => false,
        ],
        [
            'codigo' => 'pronto_nao_faturado',
            'nome' => 'Pronto e não faturado',
            'etapa' => 'Faturamento',
            'evento_inicio' => 'checklist_saida',
            'evento_fim' => 'os_enviada_financeiro',
            'modo' => self::MODO_FIM_DO_DIA,
            'nivel1_destino' => 'consultor,financeiro',
            'nivel2_apos_minutos' => 1,
            'nivel2_destino' => 'gerente',
            'unidade_offset' => 'dias',
            'critico' => false,
        ],
        [
            'codigo' => 'posvenda_7_dias',
            'nome' => 'Pós-venda em 7 dias',
            'etapa' => 'Pós-Venda',
            'evento_inicio' => 'saida_veiculo',
            'evento_fim' => 'posvenda_contato',
            'modo' => self::MODO_MINUTOS,
            'prazo_minutos' => 10080,
            'nivel1_destino' => 'consultor',
            'nivel2_apos_minutos' => 2880,
            'nivel2_destino' => 'gerente',
            'conta_horario_comercial' => false,
            'critico' => false,
        ],
        [
            'codigo' => 'diagnostico_retorno',
            'nome' => 'Diagnóstico de retorno',
            'etapa' => 'Diagnóstico',
            'evento_inicio' => 'retorno_aberto',
            'evento_fim' => 'diagnostico_fim',
            'modo' => self::MODO_MINUTOS,
            'prazo_minutos' => 120,
            'nivel1_destino' => 'chefe',
            'nivel2_apos_minutos' => 60,
            'nivel2_destino' => 'gerente',
            'critico' => false,
        ],
        [
            'codigo' => 'reclamacao',
            'nome' => 'Reclamação do cliente',
            'etapa' => 'Pós-Venda',
            'evento_inicio' => 'reclamacao_aberta',
            'evento_fim' => 'reclamacao_fechada',
            'modo' => self::MODO_MINUTOS,
            // Gerente com 36 h sem solução; proprietário aos 48 h (36 h + 12 h).
            'prazo_minutos' => 2160,
            'nivel1_destino' => 'gerente',
            'nivel3_apos_minutos' => 720,
            'nivel3_destino' => 'proprietario',
            'conta_horario_comercial' => false,
            'critico' => false,
        ],
        [
            'codigo' => 'critico_bloqueio_forcado',
            'nome' => 'Avanço forçado',
            'etapa' => null,
            'evento_inicio' => 'bloqueio_forcado',
            'evento_fim' => '',
            'modo' => self::MODO_IMEDIATO,
            'nivel1_destino' => 'gerente',
            // Só escala ao proprietário quando o avanço forçado é de pagamento ou saída.
            'nivel2_apos_minutos' => 0,
            'nivel2_destino' => 'proprietario',
            'critico' => true,
        ],
        [
            'codigo' => 'critico_saida_sem_nota',
            'nome' => 'Saída sem nota fiscal',
            'etapa' => 'Faturamento',
            'evento_inicio' => 'saida_veiculo',
            'evento_fim' => '',
            'modo' => self::MODO_IMEDIATO,
            'nivel1_destino' => 'gerente,proprietario',
            'condicao_inicio' => 'sem_nf',
            'critico' => true,
        ],
        [
            'codigo' => 'critico_diferenca_caixa',
            'nome' => 'Diferença de caixa',
            'etapa' => 'Faturamento',
            'evento_inicio' => 'caixa_diferenca',
            'evento_fim' => '',
            'modo' => self::MODO_IMEDIATO,
            'nivel1_destino' => 'gerente,proprietario',
            'critico' => true,
        ],
    ];

    protected $table = 'barrier_rules';

    protected $guarded = [];

    protected $casts = [
        'critico' => 'boolean',
        'ativo' => 'boolean',
        'conta_horario_comercial' => 'boolean',
    ];

    /** @var array<string, bool> */
    protected static array $seeded = [];

    /**
     * Semeia as regras padrão que ainda não existem (idempotente).
     */
    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $rule) {
            static::firstOrCreate(['codigo' => $rule['codigo']], $rule + ['ativo' => true]);
        }

        static::$seeded[DB::connection()->getDatabaseName()] = true;
    }

    /**
     * Garante as regras padrão uma vez por banco (cada oficina tem o seu).
     */
    public static function ensureDefaultsOnce(): void
    {
        if (! (static::$seeded[DB::connection()->getDatabaseName()] ?? false)) {
            static::ensureDefaults();
        }
    }

    public static function forgetSeeded(): void
    {
        static::$seeded = [];
    }

    public function destinos(int $nivel): array
    {
        $field = "nivel{$nivel}_destino";

        return array_values(array_filter(array_map('trim', explode(',', (string) $this->{$field}))));
    }

    /**
     * @return string[]
     */
    public function eventosInicio(): array
    {
        return $this->splitEvents($this->evento_inicio);
    }

    /**
     * @return string[]
     */
    public function eventosFim(): array
    {
        return $this->splitEvents($this->evento_fim);
    }

    /**
     * Minutos (ou dias, conforme unidade_offset) após o vencimento em que o
     * nível dispara (nível 1 = 0).
     */
    public function offsetMinutos(int $nivel): ?int
    {
        return match ($nivel) {
            1 => 0,
            2 => $this->nivel2_apos_minutos,
            3 => $this->nivel3_apos_minutos,
            default => null,
        };
    }

    protected function splitEvents(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }
}
