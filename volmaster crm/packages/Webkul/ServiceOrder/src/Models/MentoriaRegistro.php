<?php

namespace Webkul\ServiceOrder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/**
 * Registro do dia a dia que não nasce na OS (auditoria com nota, ocorrência,
 * reunião, checklist da zeladoria, conciliação, inventário...). Alimenta os
 * indicadores que a especificação define como manuais ou de rotina.
 *
 * Só inserção: nunca se edita nem se apaga; correção = novo registro que
 * aponta para o original e traz a justificativa (capítulo 1, regra 10).
 */
class MentoriaRegistro extends Model
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    /**
     * Tipos aceitos. Cada tipo diz que campos a tela de registro mostra:
     * valor (nota, horas, contagem), ok (sim/não) e o colaborador avaliado.
     *
     * @var array<string, array{label: string, grupo: string, valor?: string, ok?: string, colaborador?: string, dados?: array<string, string>}>
     */
    public const TIPOS = [
        // Gerente
        'reuniao_diaria' => ['grupo' => 'Gerente', 'label' => 'Reunião diária realizada', 'ok' => 'Realizada'],
        'reuniao_semanal' => ['grupo' => 'Gerente', 'label' => 'Reunião semanal realizada', 'ok' => 'Realizada'],
        'reuniao_mensal' => ['grupo' => 'Gerente', 'label' => 'Reunião mensal realizada', 'ok' => 'Realizada'],
        'plano_acao' => ['grupo' => 'Gerente', 'label' => 'Plano de ação criado', 'dados' => ['indicador' => 'Código do indicador (ex.: CON-04)', 'prazo' => 'Prazo (AAAA-MM-DD)']],
        'plano_acao_concluido' => ['grupo' => 'Gerente', 'label' => 'Plano de ação concluído', 'dados' => ['plano_id' => 'Número do registro do plano']],
        'auditoria_amostragem' => ['grupo' => 'Gerente', 'label' => 'Auditoria por amostragem feita', 'ok' => 'Feita'],
        'painel_consolidado' => ['grupo' => 'Gerente', 'label' => 'Painel do Dono consolidado', 'dados' => ['periodo' => 'Mês (AAAA-MM)']],
        'relatorio_gerente' => ['grupo' => 'Gerente', 'label' => 'Relatório diário de 5 linhas entregue', 'ok' => 'Entregue'],
        'folha_variaveis' => ['grupo' => 'Gerente', 'label' => 'Variáveis da folha enviadas no prazo', 'ok' => 'No prazo'],
        'folha_fechada' => ['grupo' => 'Gerente', 'label' => 'Folha fechada até o 5º dia útil', 'ok' => 'No prazo'],
        'hora_extra_sem_autorizacao' => ['grupo' => 'Gerente', 'label' => 'Hora extra sem autorização prévia', 'colaborador' => 'user'],
        'rescisao' => ['grupo' => 'Gerente', 'label' => 'Rescisão paga', 'ok' => 'Paga no prazo'],
        'falta_horas' => ['grupo' => 'Gerente', 'label' => 'Horas de falta ou atraso', 'valor' => 'Horas', 'colaborador' => 'user'],

        // Chefe e técnico
        'tempario_revisado' => ['grupo' => 'Chefe', 'label' => 'Tempário revisado no trimestre', 'ok' => 'Revisado e aprovado pelo gerente'],
        'auditoria_box' => ['grupo' => 'Técnico', 'label' => 'Auditoria semanal de box e postura', 'valor' => 'Nota (0 a 10)', 'colaborador' => 'mechanic'],
        'celular_execucao' => ['grupo' => 'Técnico', 'label' => 'Celular durante a execução (ocorrência)', 'colaborador' => 'mechanic'],

        // Peças
        'nf_entrada' => ['grupo' => 'Peças', 'label' => 'NF de entrada lançada', 'dados' => ['recebida_em' => 'Recebida em (AAAA-MM-DD HH:MM)', 'lancada_em' => 'Lançada em (AAAA-MM-DD HH:MM)']],
        'garantia_fornecedor' => ['grupo' => 'Peças', 'label' => 'Processo de garantia/devolução aberto', 'dados' => ['recebida_em' => 'Peça com defeito recebida em (AAAA-MM-DD)', 'aberta_em' => 'Processo aberto em (AAAA-MM-DD)']],
        'inventario_rotativo' => ['grupo' => 'Peças', 'label' => 'Inventário rotativo semanal', 'dados' => ['contados' => 'Itens contados', 'corretos' => 'Itens com saldo correto']],
        'auditoria_estoque' => ['grupo' => 'Peças', 'label' => 'Auditoria de organização do estoque', 'valor' => 'Nota (0 a 10)'],
        'peca_errada' => ['grupo' => 'Peças', 'label' => 'Troca por erro de código, cotação ou envio (ocorrência)'],

        // Financeiro
        'conciliacao_dia' => ['grupo' => 'Financeiro', 'label' => 'Dia conciliado', 'ok' => 'Conciliado até o dia útil seguinte'],
        'caixa_dia' => ['grupo' => 'Financeiro', 'label' => 'Fechamento do caixa do dia', 'valor' => 'Diferença em R$ (0 se fechou certo)', 'ok' => 'Diferença justificada'],
        'conta_paga' => ['grupo' => 'Financeiro', 'label' => 'Conta paga', 'ok' => 'Paga sem juros nem multa'],
        'pagamento_pecas' => ['grupo' => 'Financeiro', 'label' => 'Pagamento de peças', 'ok' => 'NF de entrada conferida antes de pagar'],
        'relatorio_financeiro' => ['grupo' => 'Financeiro', 'label' => 'Relatório diário ao dono', 'ok' => 'Completo e no horário'],
        'cobranca_contato' => ['grupo' => 'Financeiro', 'label' => 'Contato da régua de cobrança', 'valor' => 'Dia da régua (1, 7 ou 15)', 'ok' => 'Feito'],
        'contabilidade_envio' => ['grupo' => 'Financeiro', 'label' => 'Envio à contabilidade', 'ok' => 'Até a data combinada'],
        'relatorio_mensal_financeiro' => ['grupo' => 'Financeiro', 'label' => 'Relatório mensal entregue', 'ok' => 'Até o 5º dia útil'],

        // Zeladoria
        'zel_limpeza' => ['grupo' => 'Zeladoria', 'label' => 'Checklist diário de limpeza', 'ok' => 'Completo'],
        'zel_banheiro' => ['grupo' => 'Zeladoria', 'label' => 'Banheiro limpo no horário', 'ok' => 'Feito no horário'],
        'zel_cafe' => ['grupo' => 'Zeladoria', 'label' => 'Café e lanche', 'ok' => 'No horário'],
        'zel_auditoria' => ['grupo' => 'Zeladoria', 'label' => 'Auditoria semanal do ambiente', 'valor' => 'Nota (0 a 10)'],
        'zel_falta_item' => ['grupo' => 'Zeladoria', 'label' => 'Faltou item por falta de aviso (ocorrência)'],
        'zel_reclamacao' => ['grupo' => 'Zeladoria', 'label' => 'Reclamação de limpeza (ocorrência)'],

        // Dono
        'dono_prospeccao' => ['grupo' => 'Dono', 'label' => 'Visita ou prospecção de clientes', 'valor' => 'Horas na agenda'],
        'dono_reuniao_gerente' => ['grupo' => 'Dono', 'label' => 'Reunião do dono com o gerente', 'ok' => 'Realizada'],

        // Gerados pelo sistema (não aparecem no formulário de registros).
        'bonus_estorno' => ['grupo' => 'Sistema', 'label' => 'Estorno de bônus'],
        'dono_acionado' => ['grupo' => 'Sistema', 'label' => 'Dono acionado fora da alçada'],
        'perfil_funcao' => ['grupo' => 'Sistema', 'label' => 'Função da mentoria criada ou atualizada'],
        'perfil_usuario' => ['grupo' => 'Sistema', 'label' => 'Perfil de acesso do usuário alterado'],

        // Gestão de pessoas (também usados pelo cadastro de colaboradores)
        'admissao' => ['grupo' => 'Gerente', 'label' => 'Admissão', 'ok' => 'Registrada antes do início', 'colaborador' => 'user'],
        'desligamento' => ['grupo' => 'Gerente', 'label' => 'Desligamento', 'colaborador' => 'user'],
        'ferias_vencida' => ['grupo' => 'Gerente', 'label' => 'Férias vencidas (ocorrência)', 'colaborador' => 'user'],
        'rh_em_dia' => ['grupo' => 'Gerente', 'label' => 'Exame periódico e EPI em dia', 'ok' => 'Em dia', 'colaborador' => 'user'],
    ];

    protected $table = 'mentoria_registros';

    protected $guarded = [];

    protected $casts = [
        'ocorrido_em' => 'datetime',
        'criado_em' => 'datetime',
        'dados_json' => 'array',
        'ok' => 'boolean',
        'valor' => 'float',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Registros não podem ser alterados; registre uma correção.');
        });

        static::deleting(function () {
            throw new LogicException('Registros não podem ser apagados; registre uma correção.');
        });
    }

    public static function record(string $tipo, array $attributes = [], ?Carbon $ocorridoEm = null, ?int $registradoPor = null): self
    {
        if (! array_key_exists($tipo, self::TIPOS)) {
            throw new InvalidArgumentException("Tipo de registro desconhecido: {$tipo}");
        }

        return static::create(array_merge([
            'ocorrido_em' => $ocorridoEm ?? now(),
            'registrado_por' => $registradoPor ?? auth()->guard('user')->id(),
        ], $attributes, ['tipo' => $tipo]));
    }

    /**
     * Correção: novo registro que aponta para o original e o substitui nas
     * contas (o original deixa de contar), com a justificativa.
     */
    public static function correct(self $original, array $novos, string $justificativa): self
    {
        if (trim($justificativa) === '') {
            throw new InvalidArgumentException('A correção exige justificativa.');
        }

        return static::create(array_merge($original->only(['tipo', 'user_id', 'mechanic_id', 'service_order_id', 'ocorrido_em', 'valor', 'ok', 'dados_json', 'observacao']), $novos, [
            'corrige_id' => $original->id,
            'justificativa' => $justificativa,
            'registrado_por' => auth()->guard('user')->id(),
        ]));
    }

    /**
     * Registros vigentes: exclui os que já foram corrigidos por outro.
     */
    public function scopeVigentes($query)
    {
        return $query->whereNotIn('id', static::query()->whereNotNull('corrige_id')->select('corrige_id'));
    }

    public function scopeDoTipo($query, string|array $tipo)
    {
        return $query->whereIn('tipo', (array) $tipo);
    }

    public function scopeNoPeriodo($query, Carbon $from, Carbon $to)
    {
        return $query->whereBetween('ocorrido_em', [$from, $to]);
    }
}
