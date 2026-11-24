<?php

namespace Webkul\ServiceOrder\Services\Bonus;

/**
 * Marcadores e pesos por cargo e fase (capítulo 6.2) e as travas por cargo
 * (capítulo 6.3). Valores padrão da mentoria; a oficina ajusta na tabela
 * bonus_marcadores. Cada fase soma 100 pontos.
 */
class BonusCatalog
{
    /**
     * Marcadores de resultado: zeram na Fase 2 quando o gatilho de resultado
     * da Ficha de Enquadramento não foi atingido no mês.
     */
    public const RESULTADO = ['CON-09', 'CON-10', 'CON-11', 'CHE-11', 'CHE-12', 'TEC-07', 'PEC-11', 'PEC-12', 'FIN-10', 'GER-02', 'GER-03'];

    /**
     * Faixa de pagamento sobre o valor cheio: nota mínima => % do bônus.
     */
    public const FAIXAS = [90 => 100, 80 => 70, 70 => 40];

    /**
     * @return array<string, array<int, array<int, array{0: string, 1: int}|array{0: string, 1: int, 2: string}>>>
     *                                                                                                             cargo => fase => [[códigos, peso, nome?], ...]
     */
    public static function marcadores(): array
    {
        return [
            'consultor' => [
                1 => [['CON-02', 25], ['CON-03', 20], ['CON-04', 15], ['CON-06', 10], ['CON-07', 20], ['CON-08', 10]],
                2 => [['CON-02', 15], ['CON-03', 10], ['CON-07', 15], ['CON-08', 10], ['CON-09', 15], ['CON-10', 15], ['CON-11', 10], ['CON-12', 10]],
            ],
            'chefe' => [
                1 => [['CHE-01', 10], ['CHE-02', 20], ['CHE-03', 15], ['CHE-04', 15], ['CHE-08', 20], ['CHE-09', 20]],
                2 => [['CHE-02', 10], ['CHE-04', 10], ['CHE-08', 10], ['CHE-09', 10], ['CHE-12', 20], ['CHE-07', 15], ['CHE-10', 15], ['CHE-11', 10]],
            ],
            'tecnico' => [
                1 => [['TEC-04', 15], ['TEC-05', 15], ['TEC-12', 15], ['TEC-11', 15], ['TEC-15', 10], ['TEC-13', 20], ['TEC-14', 10]],
                2 => [['TEC-02', 20], ['TEC-01', 10], ['TEC-06', 10], ['TEC-07', 15], ['TEC-09', 10], ['TEC-10', 15], ['TEC-13', 10], ['TEC-11', 10]],
            ],
            'pecas' => [
                1 => [['PEC-01', 20], ['PEC-04', 15], ['PEC-07', 15], ['PEC-06', 20], ['PEC-10', 15], ['PEC-13', 15]],
                2 => [['PEC-01', 10], ['PEC-02', 15], ['PEC-03', 10], ['PEC-05', 15], ['PEC-06', 10], ['PEC-09', 15], ['PEC-12', 15], ['PEC-11', 10]],
            ],
            'financeiro' => [
                1 => [['FIN-08', 20], ['FIN-04', 15], ['FIN-05', 15], ['FIN-01', 15], ['FIN-02', 20], ['FIN-06', 15]],
                2 => [['FIN-08', 15], ['FIN-04', 10], ['FIN-02', 10], ['FIN-06', 10], ['FIN-10', 20], ['FIN-09', 10], ['FIN-11', 10], ['FIN-12,FIN-13', 15, 'Contabilidade e relatório mensal no prazo']],
            ],
            'gerente' => [
                1 => [['GER-06', 20], ['GER-11', 20], ['GER-07', 15], ['GER-10', 15], ['GER-14', 15], ['GER-12', 15]],
                2 => [['GER-01', 20], ['GER-02', 20], ['GER-03', 15], ['GER-05', 10], ['GER-08', 10], ['GER-09', 10], ['GER-14,GER-15,GER-16', 15, 'RH em dia']],
            ],
            // A zeladoria tem fase única.
            'zeladoria' => [
                1 => [['ZEL-01', 25], ['ZEL-02', 20], ['ZEL-03', 25], ['ZEL-04', 15], ['ZEL-05', 10], ['ZEL-06', 5]],
            ],
        ];
    }

    /**
     * Travas por cargo e como o sistema as detecta (capítulo 6.3).
     *
     * @return array<string, array<string, array{nome: string, automatica: bool}>>
     */
    public static function travas(): array
    {
        $t = fn (string $nome, bool $automatica = false) => ['nome' => $nome, 'automatica' => $automatica];

        return [
            'consultor' => [
                'liberado_sem_quitacao' => $t('Caminhão liberado sem quitação ou acordo assinado', true),
                'servico_sem_aprovacao' => $t('Serviço sem aprovação registrada', true),
                'desconto_fora_alcada' => $t('Desconto fora da alçada sem autorização'),
                'prazo_cliente_vermelho' => $t('Prazo a cliente vermelho sem o dono'),
                'deslocamento_sem_autorizacao' => $t('Deslocamento externo sem autorização'),
                'credito_comentado' => $t('Informação de crédito comentada'),
            ],
            'chefe' => [
                'execucao_sem_autorizacao' => $t('Execução sem autorização', true),
                'entrega_sem_teste' => $t('Entrega sem test drive ou checklist de saída', true),
                'montagem_sem_checklist' => $t('Motor ou transmissão sem checklist de montagem', true),
                'peca_sem_lancamento_chefe' => $t('Peça aplicada sem lançamento, com conhecimento do chefe'),
                'retrabalho_escondido' => $t('Retrabalho escondido'),
                'teste_sem_autorizacao' => $t('Test drive sem autorização ou condutor não habilitado', true),
            ],
            'tecnico' => [
                'servico_sem_autorizacao' => $t('Serviço sem autorização'),
                'descarte_sem_termo' => $t('Descarte de peça sem termo'),
                'regra_seguranca' => $t('Descumprir regra de segurança'),
                'retorno_negligencia' => $t('Retorno por negligência comprovada'),
            ],
            'pecas' => [
                'saida_sem_lancamento' => $t('Peça saída do estoque sem lançamento na OS', true),
                'compra_sem_autorizacao' => $t('Compra sem autorização (fora reposição de mínimo)'),
                'preco_fora_tabela' => $t('Preço fora da tabela de margem sem autorização'),
                'ajuste_sem_aprovacao' => $t('Ajuste de saldo sem aprovação'),
            ],
            'financeiro' => [
                'prazo_vermelho_sem_dono' => $t('Prazo para cliente vermelho sem o dono'),
                'pagamento_acima_alcada' => $t('Pagamento acima da alçada'),
                'saida_sem_nf' => $t('Saída sem NF fora da exceção', true),
                'caixa_sem_justificativa' => $t('Diferença de caixa não justificada', true),
                'lancamento_sem_categoria' => $t('Lançamentos sem categoria no fechamento (DRE)', true),
                'uso_indevido_credito' => $t('Uso indevido de dados de crédito'),
            ],
            'gerente' => [
                'colaborador_sem_registro' => $t('Colaborador sem registro', true),
                'ferias_vencidas' => $t('Férias vencidas por falha de controle', true),
                'despesa_sem_dono' => $t('Despesa acima da alçada sem o dono'),
                'indicador_falso' => $t('Indicador informado ao dono que se prove falso'),
            ],
            'zeladoria' => [
                'regra_seguranca_zel' => $t('Descumprir regra de segurança'),
                'falta_injustificada' => $t('Falta injustificada no mês'),
            ],
        ];
    }
}
